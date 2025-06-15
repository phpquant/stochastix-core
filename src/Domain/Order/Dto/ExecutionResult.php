<?php

namespace Stochastix\Domain\Order\Dto;

use Stochastix\Domain\Common\Enum\DirectionEnum;

final readonly class ExecutionResult
{
    /**
     * @param string      $orderId          system-generated unique ID for this execution/trade leg
     * @param string|null $clientOrderId    from the original OrderSignal
     * @param string      $filledPrice      average fill price (as a string)
     * @param string      $filledQuantity   (as a string)
     * @param string      $commissionAmount (as a string)
     * @param string      $commissionAsset  e.g., "USDT" or the base asset.
     * @param string|null $stopLossPrice    the stop-loss price for this position
     * @param string|null $takeProfitPrice  the take-profit price for this position
     * @param array|null  $enterTags        an array of tags for the entry signal
     * @param array|null  $exitTags         an array of tags/reasons for the exit signal
     */
    public function __construct(
        public string $orderId,
        public ?string $clientOrderId,
        public string $symbol,
        public DirectionEnum $direction,
        public string $filledPrice,
        public string $filledQuantity,
        public string $commissionAmount,
        public string $commissionAsset,
        public \DateTimeImmutable $executedAt,
        public ?string $stopLossPrice = null,
        public ?string $takeProfitPrice = null,
        public ?array $enterTags = null,
        public ?array $exitTags = null
    ) {
    }
}
