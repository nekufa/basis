<?php

namespace Basis\Test;

use Basis\Test;
use Exception;
use Tarantool\Mapper\Plugin\Spy;

class Mapper
{
    protected $test;
    public $serviceName;

    public function __construct(Test $test, $service)
    {
        $this->test = $test;
        $this->serviceName = $service;
    }

    public function create($space, $params)
    {
        $key = $this->serviceName.'.'.$space;
        if (array_key_exists($key, $this->test->data)) {
            return new class($params, $this->test, $key) {
                private $data;
                private $test;
                private $key;
                public function __construct($data, $test, $key)
                {
                    $this->data = $data;
                    $this->test = $test;
                    $this->key = $key;
                }
                public function save()
                {
                    if (!array_key_exists('id', $this->data)) {
                        $this->data['id'] = 1;
                        foreach ($this->test->data[$this->key] as $candidate) {
                            $this->data['id'] = max($this->data['id'], $candidate['id'] + 1);
                        }
                    }
                    $this->test->data[$this->key][] = $this->data;
                    return (object) $this->data;
                }
            };
        }
    }

    public function find(string $space, $params = [])
    {
        $key = $this->serviceName.'.'.$space;
        if (array_key_exists($key, $this->test->data)) {
            $data = $this->test->data[$key];
            foreach ($data as $i => $v) {
                if (count($params) && array_intersect_assoc($params, $v) != $params) {
                    unset($data[$i]);
                    continue;
                }
                $data[$i] = (object) $v;
            }
            $data = array_values($data);
            return $data;
        }
        return [];
    }

    public function findOne(string $space, $params = [])
    {
        $key = $this->serviceName.'.'.$space;
        if (is_numeric($params) || is_string($params)) {
            $params = [ 'id' => $params ];
        }
        if (array_key_exists($key, $this->test->data)) {
            foreach ($this->test->data[$key] as $candidate) {
                if (!count($params) || array_intersect_assoc($params, $candidate) == $params) {
                    return (object) $candidate;
                }
            }
        }
    }

    public function findOrFail(string $space, $params = [])
    {
        $result = $this->findOne($space, $params);
        if (!$result) {
            throw new Exception("No ".$space.' found using '.json_encode($params));
        }
        return $result;
    }

    public function getPlugin($class)
    {
        if ($class == Spy::class) {
            return new class {
                public function hasChanges()
                {
                    return false;
                }
            };
        }
    }

    protected $repositores = [];

    public function getRepository($space)
    {
        if (!array_key_exists($space, $this->repositores)) {
            $this->repositores[$space] = new Repository($this, $space);
        }
        return $this->repositores[$space];
    }

    public function remove($space, $params)
    {
        if (is_object($params)) {
            $params = get_object_vars($params);
        }
        $key = $this->serviceName.'.'.$space;
        if (array_key_exists($key, $this->test->data)) {
            foreach ($this->test->data[$key] as $i => $v) {
                if (count($params) && array_intersect_assoc($params, $v) == $params) {
                    unset($this->test->data[$key][$i]);
                }
            }
            $this->test->data[$key] = array_values($this->test->data[$key]);
        }
    }
}