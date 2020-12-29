<?php

namespace Basis;

use Basis\Procedure\JobQueue\Cleanup;
use Basis\Procedure\JobQueue\Take;
use Basis\Procedure\JobResult\Foreign;
use Exception;
use Psr\Log\LoggerInterface;
use Tarantool\Client\Schema\Criteria;
use Tarantool\Client\Schema\Operations;
use Tarantool\Mapper\Entity;
use Tarantool\Mapper\Plugin\Procedure;
use Tarantool\Mapper\Repository;

class Executor
{
    use Toolkit;

    public function normalize(array $request): array
    {
        if (!array_key_exists('job', $request) || !$request['job']) {
            throw new Exception("No job defined");
        }

        if (!array_key_exists('service', $request) || !$request['service']) {
            if ($this->get(Dispatcher::class)->isLocalJob($request['job'])) {
                $request['service'] = $this->app->getName();
            } else {
                $request['service'] = explode('.', $request['job'])[0];
            }
        }

        if (!array_key_exists('params', $request)) {
            $request['params'] = [];
        } else {
            $request['params'] = $this->get(Converter::class)->toArray($request['params']);
        }

        $context = $this->get(Context::class)->toArray();
        $hash = md5(json_encode($context));
        $jobContext = $this->findOrCreate('job_context', [
            'hash' => $hash
        ], [
            'activity' => time(),
            'context' => $context,
            'hash' => $hash,
        ]);

        if (!$jobContext->activity || $jobContext->activity < time() - 60) {
            $jobContext->activity = time();
            $jobContext->save();
        }

        $request['context'] = $jobContext->id;
        if (!array_key_exists('hash', $request)) {
            if (array_key_exists('job_queue_hash', $request['params'])) {
                $request['hash'] = $request['params']['job_queue_hash'];
            } else {
                $request['hash'] = microtime(true) . '.' . bin2hex(random_bytes(8));
            }
        }
        return $request;
    }

    public function initRequest($request)
    {
        $request = $this->normalize($request);
        $request['status'] = 'new';

        $params = [
            'status' => $request['status'],
            'hash' => $request['hash'],
        ];

        return $this->findOrCreate('job_queue', $params, $request);
    }

    public function send(string $job, array $params = [], string $service = null)
    {
        $this->initRequest(compact('job', 'params', 'service'));
    }

    public function dispatch(string $job, array $params = [], string $service = null)
    {
        $recipient = $this->getServiceName();
        $request = compact('job', 'params', 'service', 'recipient');
        $request = $this->normalize($request);

        $result = $this->findOne('job_result', [
            'service' => $this->getServiceName(),
            'hash' => $request['hash'],
        ]);
        if ($result) {
            if ($result->expire && $result->expire < time()) {
                $this->getMapper()->remove($result);
            } else {
                return $result->data;
            }
        }

        $this->initRequest($request);
        return $this->getResult($request['hash'], $request['service']);
    }

    public function process()
    {
        $total = $this->transferResult();
        while ($this->processQueue()) {
            $total++;
        }

        if ($total) {
            $total += $this->cleanup();
        }

        return $total;
    }

    public function cleanup()
    {
        // 24 hours inactivity for context
        $criteria = Criteria::index('activity')
            ->andKey([ time() - 24 * 60 * 60 ])
            ->andLeIterator()
            ->andLimit(100);

        $client = $this->getMapper()->getClient();
        $contexts = $client->getSpace('job_context');
        $counter = 0;
        foreach ($contexts->select($criteria) as $tuple) {
            [$jobs] = $client->call('box.space.job_queue.index.context:count', $tuple[0]);
            if ($jobs > 0) {
                $contexts->update([$tuple[0]], Operations::set('activity', time()));
            } else {
                $contexts->delete([ $tuple[0] ]);
                $counter++;
            }
        }

        // 1 minute expiration for result
        $criteria = Criteria::index('expire_id')
            ->andKey([ time() - 60 ])
            ->andLeIterator()
            ->andLimit(100);

        $results = $client->getSpace('job_result');
        $counter = 0;
        foreach ($results->select($criteria) as $tuple) {
            $results->delete([ $tuple[0] ]);
            $counter++;
        }

        return $counter;
    }

    public function processQueue()
    {
        $tuple = $this->get(Take::class)();
        if (!$tuple) {
            return;
        }
        $request = $this->getRepository('job_queue')->getInstance($tuple);

        if ($request->service != $this->getServiceName()) {
            try {
                return $this->transferRequest($request);
            } catch (Exception $e) {
                return $this->processRequest($request);
            }
        }

        return $this->processRequest($request);
    }

    protected function transferRequest(Entity $request)
    {
        $context = $request->getContext();
        $remoteContext = $this->findOrCreate("$request->service.job_context", [
            'hash' => $context->hash
        ], [
            'hash' => $context->hash,
            'context' => $context->context,
            'activity' => time(),
        ]);

        if (!$remoteContext->activity || $remoteContext->activity < time() - 60) {
            $remoteContext->activity = time();
            $remoteContext->save();
        }

        $template = $this->get(Converter::class)->toArray($request);
        $template['context'] = $remoteContext->id;
        $template['status'] = 'new';
        unset($template['id']);

        $params = [
            'hash' => $template['hash'],
            'status' => $template['status'],
        ];

        $this->findOrCreate("$request->service.job_queue", $params, $template);

        if ($request->recipient) {
            $request->status = 'transfered';
            $request->save();
        } else {
            $this->getMapper()->remove($request);
        }

        return $request;
    }

    public function processRequest($request)
    {
        $context = $this->get(Context::class);
        $backup = $context->toArray();
        $context->reset($request->getContext()->context);

        $result = $this->get(Dispatcher::class)->dispatch($request->job, $request->params, $request->service);

        $context->reset($backup);

        if ($request->recipient) {
            $this->findOrCreate('job_result', [
                'service' => $request->recipient,
                'hash' => $request->hash,
            ], [
                'service' => $request->recipient,
                'hash' => $request->hash,
                'data' => $this->get(Converter::class)->toArray($result),
                'expire' => property_exists($result, 'expire') ? $result->expire : time() - 1,
            ]);
        }

        return $this->getMapper()->remove($request);
    }

    protected function transferResult()
    {
        $remote = $this->get(Foreign::class)($this->getServiceName());
        if (count($remote)) {
            $group = [];
            foreach ($remote as $tuple) {
                $result = $this->getRepository('job_result')->getInstance($tuple);
                if (!array_key_exists($result->service, $group)) {
                    $group[$result->service] = [];
                }
                $group[$result->service][] = $result;
            }
            foreach ($group as $service => $results) {
                foreach ($results as $result) {
                    $this->findOrCreate("$service.job_result", [
                        'service' => $result->service,
                        'hash' => $result->hash,
                    ], [
                        'service' => $result->service,
                        'hash' => $result->hash,
                        'data' => $result->data,
                        'expire' => $result->expire,
                    ]);
                    $this->getMapper()->remove($result);
                }

                $this->getRepository("$result->service.job_queue")
                    ->getMapper()
                    ->getPlugin(Procedure::class)
                    ->get(Cleanup::class)($result->service);
            }
        }

        return count($remote);
    }

    public function getResult($hash, $service)
    {
        $result = $this->findOne('job_result', [
            'service' => $this->getServiceName(),
            'hash' => $hash,
        ]);

        ob_flush();

        if (!$result) {
            if (!$this->processQueue()) {
                $logger = $this->get(LoggerInterface::class);
                $logger->info([ 'msg' => 'executor result wait', 'from' => $service ]);
                $dispatcher = $this->get(Dispatcher::class);
                $dispatcher->dispatch('module.sleep', [ 'seconds' => 0.5 ]);
            }
            $this->getRepository('job_result')->flushCache();
            return $this->getResult($hash, $service);
        }
        return $this->get(Converter::class)->toObject($result->data);
    }

    public function getServiceName()
    {
        return $this->app->getName();
    }
}
