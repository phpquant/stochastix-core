<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use function Symfony\Component\String\u;

abstract class AbstractSeriesMetric implements SeriesMetricInterface
{
    protected ?array $values = null;

    public function getName(): string
    {
        return u(new \ReflectionClass($this)->getShortName())->camel()->toString();
    }

    public function getValues(): array
    {
        if ($this->values === null) {
            throw new \LogicException(sprintf('Metric Series "%s" has not been calculated yet. Call calculate() first.', static::class));
        }

        return $this->values;
    }
}
