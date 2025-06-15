<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface SummaryMetricInterface
{
    /**
     * A developer-friendly camelCase name for the metric.
     * Used as a key for service location and API output.
     */
    public function getName(): string;

    /**
     * Calculates the metric(s) and stores them internally.
     */
    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void;

    /**
     * Returns the calculated metric(s) as a key-value array.
     *
     * @return array<string, mixed>
     */
    public function getMetrics(): array;
}
