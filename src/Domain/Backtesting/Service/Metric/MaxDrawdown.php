<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;

final class MaxDrawdown extends AbstractSummaryMetric
{
    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
        $initialCapital = $config->initialCapital;
        $closedTrades = $backtestResults['closedTrades'] ?? [];

        if (empty($closedTrades)) {
            $this->calculatedMetrics = [
                'absoluteDrawdown' => 0.0,
                'maxAccountUnderwaterPercentage' => 0.0,
            ];

            return;
        }

        $peak = $initialCapital;
        $equity = $initialCapital;
        $maxDrawdownValue = '0';
        $peakAtMaxDrawdown = $initialCapital;

        foreach ($closedTrades as $trade) {
            $equity = bcadd($equity, (string) $trade['pnl'], 8);
            if (bccomp($equity, $peak, 8) > 0) {
                $peak = $equity;
            }
            $drawdown = bcsub($peak, $equity, 8);
            if (bccomp($drawdown, $maxDrawdownValue, 8) > 0) {
                $maxDrawdownValue = $drawdown;
                $peakAtMaxDrawdown = $peak;
            }
        }

        $maxDrawdownPct = '0.0';
        if (bccomp($peakAtMaxDrawdown, '0', 8) > 0) {
            $maxDrawdownPct = bcmul(bcdiv($maxDrawdownValue, $peakAtMaxDrawdown, 8), '100', 2);
        }

        $this->calculatedMetrics = [
            'absoluteDrawdown' => (float) bcadd($maxDrawdownValue, '0', 3),
            'maxAccountUnderwaterPercentage' => (float) $maxDrawdownPct,
        ];
    }
}
