<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

interface DependentSeriesMetricInterface extends SeriesMetricInterface
{
    /**
     * @return string[] an array of FQCNs for the required metric dependencies
     */
    public function getDependencies(): array;

    /**
     * Provides the metric with the calculated results of its dependencies.
     *
     * @param array<string, array> $results an associative array where keys are the FQCN of the dependency
     */
    public function setDependencyResults(array $results): void;
}
