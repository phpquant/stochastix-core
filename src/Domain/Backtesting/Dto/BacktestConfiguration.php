<?php

namespace Stochastix\Domain\Backtesting\Dto;

use Stochastix\Domain\Common\Enum\TimeframeEnum;

final readonly class BacktestConfiguration
{
    public function __construct(
        public string $strategyAlias,
        public string $strategyClass,
        public array $symbols,
        public TimeframeEnum $timeframe,
        public ?\DateTimeImmutable $startDate,
        public ?\DateTimeImmutable $endDate,
        public string $initialCapital,
        public string $stakeCurrency,
        public ?string $stakeAmountConfig,
        public array $commissionConfig,
        public string $dataSourceType,
        public string $dataSourceExchangeId,
        public array $dataSourceOptions,
        public array $strategyInputs
    ) {
    }
}
