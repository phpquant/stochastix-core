<?php

namespace Stochastix\Domain\Order\Dto;

use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;

final readonly class OrderSignal
{
    /**
     * @param OrderTypeEnum $orderType       the type of order to place
     * @param string        $quantity        this represents the number of units or stake amount (as a string)
     * @param string|null   $price           For LIMIT orders, this is the limit price. For STOP orders, this is the stop price that triggers a market order.
     * @param int|null      $timeInForceBars Automatically cancel the order if it's not filled after this many bars. Null means Good 'Til Canceled (GTC).
     * @param string|null   $clientOrderId   optional ID from strategy for tracking and cancellation
     * @param string|null   $stopLossPrice   the stop-loss price for the position once opened
     * @param string|null   $takeProfitPrice the take-profit price for the position once opened
     * @param array|null    $enterTags       an array of tags for the entry signal
     * @param array|null    $exitTags        an array of tags/reasons for the exit signal
     */
    public function __construct(
        public string $symbol,
        public DirectionEnum $direction,
        public OrderTypeEnum $orderType,
        public string $quantity,
        public ?string $price = null,
        public ?int $timeInForceBars = null,
        public ?string $clientOrderId = null,
        public ?string $stopLossPrice = null,
        public ?string $takeProfitPrice = null,
        public ?array $enterTags = null,
        public ?array $exitTags = null
    ) {
    }
}
