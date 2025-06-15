<?php

namespace Stochastix\Domain\Backtesting\Service;

interface StatisticsServiceInterface
{
    public function calculate(array $results): array;
}
