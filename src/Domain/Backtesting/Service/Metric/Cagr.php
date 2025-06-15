<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;

final class Cagr extends AbstractSummaryMetric
{
    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
        $initialCapital = (string) $config->initialCapital;
        $finalCapital = (string) $backtestResults['finalCapital'];
        $closedTrades = $backtestResults['closedTrades'] ?? [];

        $startDate = $config->startDate;
        if ($startDate === null && !empty($closedTrades)) {
            $startDate = new \DateTimeImmutable($closedTrades[0]['entryTime']);
        }

        $endDate = $config->endDate;
        if ($endDate === null && !empty($closedTrades)) {
            $endDate = new \DateTimeImmutable(end($closedTrades)['exitTime']);
        }

        if ($startDate === null || $endDate === null) {
            $this->calculatedMetrics = ['cagrPercentage' => null];

            return;
        }

        $years = $endDate->diff($startDate)->days / 365;

        if ($years <= 0 || (float) $initialCapital <= 0) {
            $this->calculatedMetrics = ['cagrPercentage' => null];

            return;
        }

        $growthRatio = bcdiv($finalCapital, $initialCapital, 16);
        $exponent = bcdiv('1', (string) $years, 16);

        $cagr = ((float) $growthRatio ** (float) $exponent) - 1;

        $this->calculatedMetrics = ['cagrPercentage' => $cagr * 100];
    }
}
