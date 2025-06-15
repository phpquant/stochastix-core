<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Common\Util\Math;

final class SortinoRatio extends AbstractSummaryMetric
{
    private const int INTERNAL_SCALE = 12;

    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
        $closedTrades = $backtestResults['closedTrades'] ?? [];
        if (count($closedTrades) < 2) {
            $this->calculatedMetrics = ['sortino' => null];

            return;
        }

        $allPnls = array_map('strval', array_column($closedTrades, 'pnl'));
        $losingPnls = array_filter($allPnls, static fn ($pnl) => bccomp($pnl, '0', self::INTERNAL_SCALE) < 0);

        $meanPnl = Math::mean($allPnls, self::INTERNAL_SCALE);

        // Calculate downside deviation (stddev of losing trades)
        // Note: some definitions use returns below a target (e.g., 0), not just losses. This is a common implementation.
        $downsideDeviation = Math::standardDeviation($losingPnls, self::INTERNAL_SCALE, false); // Population stddev of losses

        if (bccomp($downsideDeviation, '0', self::INTERNAL_SCALE) === 0) {
            $this->calculatedMetrics = ['sortino' => bccomp($meanPnl, '0', self::INTERNAL_SCALE) > 0 ? 'INF' : null];

            return;
        }

        // For simplicity, we use a risk-free rate of 0 for Sortino for now.
        $numerator = $meanPnl;
        $sortino = bcdiv($numerator, $downsideDeviation, 3);

        $this->calculatedMetrics = ['sortino' => (float) $sortino];
    }
}
