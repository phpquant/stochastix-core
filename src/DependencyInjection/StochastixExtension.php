<?php

namespace Stochastix\DependencyInjection;

use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;
use Symfony\Component\Yaml\Yaml;

final class StochastixExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    public function getAlias(): string
    {
        return 'stochastix';
    }

    public function prepend(ContainerBuilder $container): void
    {
        $extensionConfig = $container->getExtensionConfig('stochastix');
        $prioritizedConfigNames = $extensionConfig[0]['overridden_configurations'] ?? [];
        $prioritizedConfigs = [];
        $extensions = $container->getExtensions();

        foreach (Yaml::parseFile(__DIR__ . '/../../config/app.yaml') as $name => $config) {
            if (empty($extensions[$name])) {
                continue;
            }

            if (\in_array($name, $prioritizedConfigNames, true)) {
                if (!\array_key_exists($name, $prioritizedConfigs)) {
                    $prioritizedConfigs[$name] = [];
                }

                $prioritizedConfigs[$name][] = $config;
            } else {
                $this->mergeConfigIntoOne($container, $name, $config);
            }
        }

        foreach ($prioritizedConfigNames as $name) {
            if (empty($prioritizedConfigs[$name])) {
                continue;
            }

            foreach ($prioritizedConfigs[$name] as $config) {
                $this->mergeConfigIntoOne($container, $name, $config, true);
            }
        }
    }

    protected function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $container->setParameter('stochastix.defaults', $mergedConfig['defaults'] ?? []);
        $this->registerStochastixParameters($container, $mergedConfig);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');
    }

    /**
     * Recursively processes the bundle's configuration array and sets each
     * value as a dot-cased parameter in the container.
     */
    private function registerStochastixParameters(ContainerBuilder $container, array $config, string $parentKey = 'stochastix'): void
    {
        foreach ($config as $key => $value) {
            $parameterKey = "$parentKey.$key";

            if (is_array($value)) {
                $this->registerStochastixParameters($container, $value, $parameterKey);
            } else {
                $container->setParameter($parameterKey, $value);
            }
        }
    }

    public function getConfiguration(array $config, ContainerBuilder $container): ConfigurationInterface
    {
        return new StochastixConfiguration();
    }

    private function mergeConfigIntoOne(
        ContainerBuilder $container,
        string $name,
        array $config = [],
        bool $reverse = false,
    ): void {
        $originalConfig = $container->getExtensionConfig($name);
        if (!\count($originalConfig)) {
            $originalConfig[] = [];
        }

        $originalConfig[0] = $reverse
            ? $this->mergeDistinct($originalConfig[0], $config)
            : $this->mergeDistinct($config, $originalConfig[0]);

        $this->setExtensionConfig($container, $name, $originalConfig);
    }

    private function mergeDistinct(array $first, array $second): array
    {
        foreach ($second as $index => $value) {
            if (\is_int($index) && !\in_array($value, $first, true)) {
                $first[] = $value;
            } elseif (!\array_key_exists($index, $first)) {
                $first[$index] = $value;
            } elseif (\is_array($value)) {
                if (\is_array($first[$index])) {
                    $first[$index] = $this->mergeDistinct($first[$index], $value);
                } else {
                    $first[$index] = $value;
                }
            } else {
                $first[$index] = $value;
            }
        }

        return $first;
    }

    private function setExtensionConfig(ContainerBuilder $container, string $name, array $config = []): void
    {
        $classRef = new \ReflectionClass(ContainerBuilder::class);
        $extensionConfigsRef = $classRef->getProperty('extensionConfigs');

        $newConfig = $extensionConfigsRef->getValue($container);
        $newConfig[$name] = $config;
        $extensionConfigsRef->setValue($container, $newConfig);
    }
}
