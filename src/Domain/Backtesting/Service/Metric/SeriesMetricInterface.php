<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface SeriesMetricInterface
{
    /**
     * A developer-friendly camelCase name for the metric series.
     */
    public function getName(): string;

    /**
     * Calculates the metric series and stores it internally.
     */
    public function calculate(array $backtestResults): void;

    /**
     * @return float[]
     */
    public function getValues(): array;
}
