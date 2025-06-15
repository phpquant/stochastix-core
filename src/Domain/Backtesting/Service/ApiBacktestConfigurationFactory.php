<?php

namespace Stochastix\Domain\Backtesting\Service;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Dto\LaunchBacktestRequestDto;
use Stochastix\Domain\Common\Enum\TimeframeEnum;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class ApiBacktestConfigurationFactory
{
    /**
     * @param array<string, mixed> $globalDefaults
     */
    public function __construct(
        private StrategyRegistryInterface $strategyRegistry,
        #[Autowire('%stochastix.defaults%')]
        private array $globalDefaults
    ) {
    }

    public function create(LaunchBacktestRequestDto $requestDto): BacktestConfiguration
    {
        $strategyMetadata = $this->strategyRegistry->getStrategyMetadata($requestDto->strategyAlias);
        $strategyInstance = $this->strategyRegistry->getStrategy($requestDto->strategyAlias);

        if (!$strategyInstance || !$strategyMetadata) {
            throw new \InvalidArgumentException("Strategy with alias '{$requestDto->strategyAlias}' not found.");
        }
        $strategyClass = get_class($strategyInstance);

        try {
            $startDate = $requestDto->startDate ? new \DateTimeImmutable($requestDto->startDate) : null;
            $endDate = $requestDto->endDate ? new \DateTimeImmutable($requestDto->endDate) : null;
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid date format provided for start or end date: ' . $e->getMessage());
        }

        if ($startDate && $endDate && $endDate < $startDate) {
            throw new \InvalidArgumentException('End date must be after or the same as start date.');
        }

        // Use the enforced timeframe from the strategy if it exists, otherwise use the request's timeframe.
        $timeframeEnum = $strategyMetadata->timeframe ?? TimeframeEnum::tryFrom($requestDto->timeframe);
        if (!$timeframeEnum) {
            throw new \InvalidArgumentException("Invalid timeframe value: {$requestDto->timeframe}.");
        }

        $dataSourceType = $this->globalDefaults['data_source']['type'] ?? 'stchx_binary';
        $dataSourceOptionsKey = match ($dataSourceType) {
            'csv' => 'csv_options',
            'database' => 'database_options',
            'stchx_binary' => 'stchx_binary_options',
            default => 'stchx_binary_options',
        };
        $dataSourceOptions = $this->globalDefaults['data_source'][$dataSourceOptionsKey] ?? [];

        $defaultCommission = $this->globalDefaults['commission'] ?? [];
        $requestCommission = $requestDto->commissionConfig;
        $commissionConfig = $requestCommission ?? $defaultCommission;

        if (isset($commissionConfig['rate']) && is_numeric($commissionConfig['rate'])) {
            $commissionConfig['rate'] = (string) $commissionConfig['rate'];
        }
        if (isset($commissionConfig['amount']) && is_numeric($commissionConfig['amount'])) {
            $commissionConfig['amount'] = (string) $commissionConfig['amount'];
        }

        $exchangeId = $requestDto->dataSourceExchangeId ?? $this->globalDefaults['data_source']['exchange_id'] ?? null;
        if ($exchangeId === null) {
            throw new \InvalidArgumentException('Data source exchange ID is not configured in the request or in global defaults.');
        }

        return new BacktestConfiguration(
            strategyAlias: $requestDto->strategyAlias,
            strategyClass: $strategyClass,
            symbols: $requestDto->symbols,
            timeframe: $timeframeEnum,
            startDate: $startDate,
            endDate: $endDate,
            initialCapital: $requestDto->initialCapital,
            stakeCurrency: $requestDto->stakeCurrency,
            stakeAmountConfig: $requestDto->stakeAmountConfig,
            commissionConfig: $commissionConfig,
            dataSourceType: $dataSourceType,
            dataSourceExchangeId: $exchangeId,
            dataSourceOptions: $dataSourceOptions,
            strategyInputs: $requestDto->inputs
        );
    }
}
