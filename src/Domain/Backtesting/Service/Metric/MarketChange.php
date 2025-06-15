<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;

final class MarketChange extends AbstractSummaryMetric
{
    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
        $firstPrice = $backtestResults['marketFirstPrice'] ?? null;
        $lastPrice = $backtestResults['marketLastPrice'] ?? null;

        if ($firstPrice === null || $lastPrice === null || (float) $firstPrice === 0.0) {
            $this->calculatedMetrics = ['marketChangePercentage' => null];

            return;
        }

        $change = bcsub((string) $lastPrice, (string) $firstPrice, 8);
        $percentage = bcmul(bcdiv($change, (string) $firstPrice, 8), '100', 2);

        $this->calculatedMetrics = ['marketChangePercentage' => (float) $percentage];
    }
}
