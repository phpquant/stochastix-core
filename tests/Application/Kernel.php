<?php

namespace Stochastix\Tests\Application;

use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Stochastix\StochastixBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\MercureBundle\MercureBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    public function registerBundles(): iterable
    {
        return [
            new FrameworkBundle(),
            new DoctrineBundle(),
            new MercureBundle(),
            new StochastixBundle(),
        ];
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        // Store cache in the system's temp directory to avoid cluttering the project
        return sys_get_temp_dir() . '/stochastix_bundle/cache/' . $this->getEnvironment();
    }

    public function getLogDir(): string
    {
        // Store logs in the system's temp directory
        return sys_get_temp_dir() . '/stochastix_bundle/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        // Load the minimal configuration needed for our tests
        $loader->load(__DIR__ . '/config/framework.yaml');
        $loader->load(__DIR__ . '/config/mercure.yaml');
        $loader->load(__DIR__ . '/config/stochastix.yaml');
    }
}
