<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

final class Alpha extends AbstractSeriesMetric implements DependentSeriesMetricInterface
{
    private ?array $portfolioReturns = null;
    private ?array $benchmarkReturns = null;
    private ?array $betaValues = null;

    public function getDependencies(): array
    {
        return [Equity::class, Benchmark::class, Beta::class];
    }

    public function setDependencyResults(array $results): void
    {
        $equitySeries = $results[Equity::class]['values'] ?? [];
        $benchmarkSeries = $results[Benchmark::class]['values'] ?? [];

        $this->portfolioReturns = $this->calculateReturns($equitySeries);
        $this->benchmarkReturns = $this->calculateReturns($benchmarkSeries);
        $this->betaValues = $results[Beta::class]['values'] ?? [];
    }

    private function calculateReturns(array $series): array
    {
        $returns = [];
        for ($i = 1; $i < count($series); ++$i) {
            if ((float) $series[$i - 1] !== 0.0) {
                $change = bcsub((string) $series[$i], (string) $series[$i - 1]);
                $returns[] = bcdiv($change, (string) $series[$i - 1]);
            } else {
                $returns[] = '0.0';
            }
        }

        return $returns;
    }

    public function calculate(array $backtestResults): void
    {
        if ($this->portfolioReturns === null || $this->benchmarkReturns === null || $this->betaValues === null) {
            throw new \LogicException('Dependencies were not set for Alpha calculation.');
        }

        $alphaValues = [];
        $returnCount = count($this->portfolioReturns);

        for ($i = 0; $i < $returnCount; ++$i) {
            $beta = $this->betaValues[$i + 1] ?? null;

            if ($beta === null) {
                $alphaValues[] = null;
                continue;
            }

            $riskAdjustedReturn = bcmul($this->benchmarkReturns[$i], (string) $beta);
            $alpha = bcsub($this->portfolioReturns[$i], $riskAdjustedReturn);
            $alphaValues[] = (float) $alpha;
        }

        $marketDataCount = count($backtestResults['marketData'] ?? []);
        $paddingCount = $marketDataCount - count($alphaValues);

        $this->values = ($paddingCount > 0) ? array_merge(array_fill(0, $paddingCount, null), $alphaValues) : $alphaValues;
    }
}
