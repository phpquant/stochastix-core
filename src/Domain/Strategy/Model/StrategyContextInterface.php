<?php

namespace Stochastix\Domain\Strategy\Model;

use Ds\Map;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Indicator\Model\IndicatorManagerInterface;
use Stochastix\Domain\Order\Model\OrderManagerInterface;

interface StrategyContextInterface
{
    public function getIndicators(): IndicatorManagerInterface;

    public function getOrders(): OrderManagerInterface;

    public function getCursor(): BacktestCursor;

    public function getCurrentSymbol(): ?string;

    public function setCurrentSymbol(?string $symbol): void;

    /**
     * @return Map<string, mixed>
     */
    public function getDataframes(): Map;
}
