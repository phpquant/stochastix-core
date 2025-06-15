<?php

namespace Stochastix\Domain\Backtesting\Service;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Common\Enum\TimeframeEnum;
use Stochastix\Domain\Strategy\Attribute\Input;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class ConfigurationResolver
{
    public function __construct(
        private StrategyRegistryInterface $strategyRegistry,
        #[Autowire('%stochastix.defaults%')]
        private array $globalDefaults
    ) {
    }

    public function resolve(InputInterface $input): BacktestConfiguration
    {
        $strategyAlias = $input->getArgument('strategy-alias');
        $strategy = $this->strategyRegistry->getStrategy($strategyAlias);
        if (!$strategy) {
            throw new \InvalidArgumentException("Strategy with alias '{$strategyAlias}' not found.");
        }
        $strategyClass = get_class($strategy);
        $strategyMetadata = $this->strategyRegistry->getStrategyMetadata($strategyAlias);

        $attributeDefaults = $this->getAttributeDefaults($strategyClass);
        $yamlDefaults = $this->globalDefaults;
        $jsonOverrides = $this->getJsonOverrides($input);
        $cliOverrides = $this->getCliOverrides($input);

        $mergedConfig = array_replace_recursive(
            ['commission' => ['type' => 'percentage', 'rate' => 0.001, 'amount' => null, 'asset' => null]],
            $attributeDefaults['core'],
            $yamlDefaults,
            $jsonOverrides['core'] ?? [],
            $cliOverrides['core']
        );

        if ($strategyMetadata?->timeframe !== null) {
            $mergedConfig['timeframe'] = $strategyMetadata->timeframe->value;
        }

        $mergedInputs = array_merge(
            $attributeDefaults['inputs'],
            $jsonOverrides['inputs'] ?? [],
            $cliOverrides['inputs']
        );

        $mergedConfig['start_date'] ??= null;
        $mergedConfig['end_date'] ??= null;

        $this->validateRequired($mergedConfig, ['symbols', 'timeframe', 'commission']);
        $this->sanitizeTypes($mergedConfig, $mergedInputs);

        return new BacktestConfiguration(
            strategyAlias: $strategyAlias,
            strategyClass: $strategyClass,
            symbols: (array) $mergedConfig['symbols'],
            timeframe: TimeframeEnum::from($mergedConfig['timeframe']),
            startDate: $mergedConfig['start_date'] ? new \DateTimeImmutable($mergedConfig['start_date']) : null,
            endDate: $mergedConfig['end_date'] ? new \DateTimeImmutable($mergedConfig['end_date']) : null,
            initialCapital: (string) $mergedConfig['initial_capital'],
            stakeCurrency: (string) $mergedConfig['stake_currency'],
            stakeAmountConfig: !empty($mergedConfig['stake_amount']) ? (string) $mergedConfig['stake_amount'] : null,
            commissionConfig: (array) $mergedConfig['commission'],
            dataSourceType: (string) $mergedConfig['data_source']['type'],
            dataSourceExchangeId: (string) $mergedConfig['data_source']['exchange_id'],
            dataSourceOptions: (array) ($mergedConfig['data_source']['csv_options'] ?? $mergedConfig['data_source']['database_options'] ?? $mergedConfig['data_source']['stchx_binary_options'] ?? []),
            strategyInputs: $mergedInputs
        );
    }

    private function getAttributeDefaults(string $strategyClass): array
    {
        $reflectionClass = new \ReflectionClass($strategyClass);
        $inputs = [];
        $core = [];

        foreach ($reflectionClass->getProperties(\ReflectionProperty::IS_PRIVATE | \ReflectionProperty::IS_PROTECTED | \ReflectionProperty::IS_PUBLIC) as $property) {
            $inputAttributes = $property->getAttributes(Input::class);
            if (empty($inputAttributes)) {
                continue;
            }
            $inputName = $property->getName();
            if ($property->hasDefaultValue()) {
                $defaultValue = $property->getDefaultValue();

                if ($defaultValue instanceof \BackedEnum) {
                    $defaultValue = $defaultValue->value;
                }

                $inputs[$inputName] = $defaultValue;
            }
        }

        return ['core' => $core, 'inputs' => $inputs];
    }

    private function getJsonOverrides(InputInterface $input): array
    {
        $jsonPath = $input->getOption('load-config');
        if (!$jsonPath) {
            return [];
        }

        if (!file_exists($jsonPath) || !is_readable($jsonPath)) {
            throw new \InvalidArgumentException("JSON config file not found or not readable: {$jsonPath}");
        }

        $jsonContent = file_get_contents($jsonPath);
        $decoded = json_decode($jsonContent, true, 512, JSON_THROW_ON_ERROR);

        return ['core' => $decoded['core'] ?? [], 'inputs' => $decoded['inputs'] ?? []];
    }

    private function getCliOverrides(InputInterface $input): array
    {
        $core = [];
        $inputs = [];

        foreach ($input->getOption('input') as $inputPair) {
            $parts = explode(':', $inputPair, 2);
            if (count($parts) === 2) {
                $inputs[trim($parts[0])] = trim($parts[1]);
            }
        }

        foreach ($input->getOption('config') as $configPair) {
            $parts = explode(':', $configPair, 2);
            if (count($parts) === 2) {
                $core[trim($parts[0])] = trim($parts[1]);
            }
        }
        if ($input->getOption('symbol')) {
            $core['symbols'] = $input->getOption('symbol');
        }
        if ($input->getOption('timeframe')) {
            $core['timeframe'] = $input->getOption('timeframe');
        }
        if ($input->getOption('start-date')) {
            $core['start_date'] = $input->getOption('start-date');
        }
        if ($input->getOption('end-date')) {
            $core['end_date'] = $input->getOption('end-date');
        }

        return ['core' => $core, 'inputs' => $inputs];
    }

    private function validateRequired(array $config, array $requiredKeys): void
    {
        foreach ($requiredKeys as $key) {
            if (empty($config[$key])) {
                throw new \InvalidArgumentException("Missing required configuration parameter: '{$key}'. Please provide it via YAML, JSON, or CLI.");
            }
        }
    }

    private function sanitizeTypes(array &$coreConfig, array &$inputConfig): void
    {
        if (isset($coreConfig['initial_capital'])) {
            $coreConfig['initial_capital'] = (string) $coreConfig['initial_capital'];
        }
        if (isset($coreConfig['commission']['rate'])) {
            $coreConfig['commission']['rate'] = (string) $coreConfig['commission']['rate'];
        }
        if (isset($coreConfig['commission']['amount'])) {
            $coreConfig['commission']['amount'] = (string) $coreConfig['commission']['amount'];
        }
    }
}
