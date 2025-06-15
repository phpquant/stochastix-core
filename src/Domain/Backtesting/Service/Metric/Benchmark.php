<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

final class Benchmark extends AbstractSeriesMetric
{
    public function calculate(array $backtestResults): void
    {
        $config = $backtestResults['config'];
        $marketData = $backtestResults['marketData'] ?? [];
        $firstPrice = $backtestResults['marketFirstPrice'] ?? null;
        $initialCapital = $config->initialCapital;

        if (empty($marketData) || $firstPrice === null || (float) $firstPrice === 0.0) {
            $this->values = [];

            return;
        }

        $benchmarkValues = [];
        foreach ($marketData as $bar) {
            $currentClose = (string) $bar['close'];
            $priceRatio = bcdiv($currentClose, (string) $firstPrice);
            $benchmarkValue = bcmul($initialCapital, $priceRatio);
            $benchmarkValues[] = (float) $benchmarkValue;
        }

        $this->values = $benchmarkValues;
    }
}
