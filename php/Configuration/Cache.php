<?php

namespace Basis\Configuration;

use Basis\Container;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\ChainAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

class Cache
{
    public function init(Container $container)
    {
        $container->share(ApcuAdapter::class, function () {
            return new ApcuAdapter();
        });

        $container->share(ArrayAdapter::class, function () {
            return new ArrayAdapter();
        });

        $container->share(PhpFilesAdapter::class, function () {
            return new PhpFilesAdapter('', 0, 'cache');
        });

        if (getenv('SERVICE_ENVIRONMENT') === 'testing') {
            $container->share(AdapterInterface::class, ArrayAdapter::class);
        } elseif (in_array('apcu', get_loaded_extensions())) {
            $container->share(AdapterInterface::class, function () use ($container) {
                return new ChainAdapter([
                    $container->get(ArrayAdapter::class),
                    $container->get(ApcuAdapter::class),
                    $container->get(PhpFilesAdapter::class),
                ]);
            });
        } else {
            $container->share(AdapterInterface::class, function () use ($container) {
                return new ChainAdapter([
                    $container->get(ArrayAdapter::class),
                    $container->get(PhpFilesAdapter::class),
                ]);
            });
        }
    }
}
