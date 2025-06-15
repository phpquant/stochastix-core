<?php

namespace Stochastix\Domain\Backtesting\Service;

interface SeriesMetricServiceInterface
{
    public function calculate(array $backtestResults): array;
}
