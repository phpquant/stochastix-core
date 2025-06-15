<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

final class Drawdown extends AbstractSeriesMetric implements DependentSeriesMetricInterface
{
    private ?array $equityCurve = null;

    public function getDependencies(): array
    {
        return [Equity::class];
    }

    public function setDependencyResults(array $results): void
    {
        $this->equityCurve = $results[Equity::class]['values'] ?? null;
    }

    public function calculate(array $backtestResults): void
    {
        if ($this->equityCurve === null) {
            throw new \LogicException('Equity dependency not met for Drawdown calculation.');
        }

        $initialCapital = $backtestResults['config']->initialCapital;
        $peak = $initialCapital;
        $drawdownValues = [];

        foreach ($this->equityCurve as $equityValue) {
            $peak = bccomp((string) $equityValue, $peak) > 0 ? (string) $equityValue : $peak;
            $drawdown = bcsub((string) $equityValue, $peak);
            $drawdownPercentage = '0.0';
            if (bccomp($peak, '0') > 0) {
                $drawdownPercentage = bcmul(bcdiv($drawdown, $peak), '100', 4);
            }
            $drawdownValues[] = (float) $drawdownPercentage;
        }

        $this->values = $drawdownValues;
    }
}
