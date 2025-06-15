<?php

namespace Stochastix\Domain\Strategy\Model;

use Ds\Map;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Indicator\Model\IndicatorManagerInterface;
use Stochastix\Domain\Order\Model\OrderManagerInterface;

final class StrategyContext implements StrategyContextInterface
{
    public ?string $currentSymbol = null;

    public function __construct(
        public readonly IndicatorManagerInterface $indicators,
        public readonly OrderManagerInterface $orders,
        public readonly BacktestCursor $cursor,
        public readonly Map $dataframes,
    ) {
    }

    public function getIndicators(): IndicatorManagerInterface
    {
        return $this->indicators;
    }

    public function getOrders(): OrderManagerInterface
    {
        return $this->orders;
    }

    public function getCursor(): BacktestCursor
    {
        return $this->cursor;
    }

    public function getCurrentSymbol(): ?string
    {
        return $this->currentSymbol;
    }

    public function setCurrentSymbol(?string $symbol): void
    {
        $this->currentSymbol = $symbol;
    }

    public function getDataframes(): Map
    {
        return $this->dataframes;
    }
}
