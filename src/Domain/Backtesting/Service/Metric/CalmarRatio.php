<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;

final class CalmarRatio extends AbstractSummaryMetric
{
    public function __construct(private readonly Cagr $cagrMetric, private readonly MaxDrawdown $maxDrawdownMetric)
    {
    }

    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
        $this->cagrMetric->calculate($backtestResults, $config, $options);
        $this->maxDrawdownMetric->calculate($backtestResults, $config, $options);

        $cagrMetrics = $this->cagrMetric->getMetrics();
        $drawdownMetrics = $this->maxDrawdownMetric->getMetrics();

        $cagr = $cagrMetrics['cagrPercentage'];
        $maxDrawdown = $drawdownMetrics['maxAccountUnderwaterPercentage'];

        if ($cagr === null || $maxDrawdown === null || $maxDrawdown == 0) {
            $this->calculatedMetrics = ['calmar' => null];

            return;
        }

        $this->calculatedMetrics = ['calmar' => ($cagr / 100) / ($maxDrawdown / 100)];
    }
}
