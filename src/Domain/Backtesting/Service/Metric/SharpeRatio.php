<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Common\Util\Math;

final class SharpeRatio extends AbstractSummaryMetric
{
    public const string OPTION_ANNUAL_RISK_FREE_RATE = 'annual_risk_free_rate';
    public const string DEFAULT_ANNUAL_RISK_FREE_RATE = '0.02';
    private const int INTERNAL_CALCULATION_SCALE = 12;
    private const string BC_DAYS_IN_YEAR = '365.0';

    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
        $closedTrades = $backtestResults['closedTrades'] ?? [];
        if (count($closedTrades) < 2) {
            $this->calculatedMetrics = ['sharpe' => null];

            return;
        }

        $initialCapital = (string) $config->initialCapital;
        $pnlValues = array_map('strval', array_column($closedTrades, 'pnl'));
        $meanPnl = Math::mean($pnlValues, self::INTERNAL_CALCULATION_SCALE);
        $stdDevPnl = Math::standardDeviation($pnlValues, self::INTERNAL_CALCULATION_SCALE, true);

        if (bccomp($stdDevPnl, '0', self::INTERNAL_CALCULATION_SCALE) === 0) {
            $this->calculatedMetrics = ['sharpe' => bccomp($meanPnl, '0', self::INTERNAL_CALCULATION_SCALE) > 0 ? 'INF' : null];

            return;
        }

        $annualRiskFreeRate = (string) ($options[self::OPTION_ANNUAL_RISK_FREE_RATE] ?? self::DEFAULT_ANNUAL_RISK_FREE_RATE);
        $averageHoldingDays = $this->calculateAverageHoldingPeriodDays($closedTrades);

        $riskFreeReturnComponent = '0';
        if ($averageHoldingDays > 0) {
            $ratePerPeriod = bcdiv((string) $averageHoldingDays, self::BC_DAYS_IN_YEAR, self::INTERNAL_CALCULATION_SCALE);
            $effectiveRate = bcmul($annualRiskFreeRate, $ratePerPeriod, self::INTERNAL_CALCULATION_SCALE);
            $riskFreeReturnComponent = bcmul($initialCapital, $effectiveRate, self::INTERNAL_CALCULATION_SCALE);
        }

        $numerator = bcsub($meanPnl, $riskFreeReturnComponent, self::INTERNAL_CALCULATION_SCALE);
        $sharpeRatio = bcdiv($numerator, $stdDevPnl, 3);

        $this->calculatedMetrics = ['sharpe' => is_numeric($sharpeRatio) ? (float) $sharpeRatio : $sharpeRatio];
    }

    private function calculateAverageHoldingPeriodDays(array $closedTrades): float
    {
        if (empty($closedTrades)) {
            return 0.0;
        }
        $totalDurationSeconds = 0;
        $validTradesCount = 0;
        foreach ($closedTrades as $trade) {
            try {
                $entryTime = new \DateTimeImmutable($trade['entryTime']);
                $exitTime = new \DateTimeImmutable($trade['exitTime']);
                if ($exitTime >= $entryTime) {
                    $totalDurationSeconds += ($exitTime->getTimestamp() - $entryTime->getTimestamp());
                    ++$validTradesCount;
                }
            } catch (\Exception) {
                continue;
            }
        }
        if ($validTradesCount === 0) {
            return 0.0;
        }

        return ($totalDurationSeconds / $validTradesCount) / (60 * 60 * 24);
    }
}
