<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Common\Util\Math;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class Beta extends AbstractSeriesMetric implements DependentSeriesMetricInterface
{
    private ?array $portfolioReturns = null;
    private ?array $benchmarkReturns = null;

    public function __construct(
        #[Autowire('%stochastix.metrics.beta.rolling_window%')]
        private readonly int $rollingWindow
    ) {
    }

    public function getDependencies(): array
    {
        return [Equity::class, Benchmark::class];
    }

    public function setDependencyResults(array $results): void
    {
        $equitySeries = $results[Equity::class]['values'] ?? [];
        $benchmarkSeries = $results[Benchmark::class]['values'] ?? [];
        $this->portfolioReturns = $this->calculateReturns($equitySeries);
        $this->benchmarkReturns = $this->calculateReturns($benchmarkSeries);
    }

    private function calculateReturns(array $series): array
    {
        if (count($series) < 2) {
            return [];
        }

        $returns = [];
        // The first value in the series is our constant baseline for calculating returns.
        $initialValue = (float) ($series[0] ?? 0.0);

        if ($initialValue === 0.0) {
            // If initial value is zero, returns cannot be meaningfully calculated as percentages.
            return array_fill(0, count($series) - 1, '0.0');
        }

        for ($i = 1, $iMax = count($series); $i < $iMax; ++$i) {
            // Calculate the simple change relative to the previous period.
            $change = bcsub((string) $series[$i], (string) $series[$i - 1], 12);
            // Normalize this change by the initial value of the series to get a consistent return measure.
            // This maintains the linear relationship for leveraged and short scenarios.
            $returns[] = bcdiv($change, (string) $initialValue, 12);
        }

        return $returns;
    }

    public function calculate(array $backtestResults): void
    {
        if ($this->portfolioReturns === null || $this->benchmarkReturns === null) {
            throw new \LogicException('Dependencies not set for Beta calculation.');
        }

        $marketData = $backtestResults['marketData'] ?? [];
        $marketDataCount = count($marketData);

        if ($marketDataCount < $this->rollingWindow) {
            $this->values = array_fill(0, $marketDataCount, null);

            return;
        }

        $betaValues = [];
        // The first data point corresponds to t=0 and has no return, so Beta is always null.
        $betaValues[] = null;

        // Loop through the returns array (which has n-1 elements).
        for ($i = 0, $iMax = count($this->portfolioReturns); $i < $iMax; ++$i) {
            // To calculate Beta for a window of returns, we must have enough historical returns.
            // The number of returns available up to index `i` is `i + 1`.
            if (($i + 1) < $this->rollingWindow) {
                $betaValues[] = null;
                continue;
            }

            $portfolioWindow = array_slice($this->portfolioReturns, $i - $this->rollingWindow + 1, $this->rollingWindow);
            $benchmarkWindow = array_slice($this->benchmarkReturns, $i - $this->rollingWindow + 1, $this->rollingWindow);

            $variance = Math::variance($benchmarkWindow, 8, false);
            if (bccomp($variance, '0', 8) === 0) {
                $betaValues[] = null;
                continue;
            }

            $covariance = Math::covariance($portfolioWindow, $benchmarkWindow, 8, false);
            $betaValues[] = (float) bcdiv($covariance, $variance, 4);
        }

        $this->values = $betaValues;
    }
}
