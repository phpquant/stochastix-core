<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use function Symfony\Component\String\u;

abstract class AbstractSummaryMetric implements SummaryMetricInterface
{
    protected ?array $calculatedMetrics = null;

    public function getName(): string
    {
        return u(new \ReflectionClass($this)->getShortName())->camel()->toString();
    }

    public function getMetrics(): array
    {
        if ($this->calculatedMetrics === null) {
            throw new \LogicException(sprintf('Metric "%s" has not been calculated yet. Call calculate() first.', static::class));
        }

        return $this->calculatedMetrics;
    }
}
