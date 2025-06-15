<?php

namespace Stochastix\Domain\Order\Dto;

use Stochastix\Domain\Common\Enum\DirectionEnum;

final readonly class PositionDto
{
    /**
     * @param string      $positionId             a unique identifier for this open position
     * @param string      $entryPrice             the entry price (as a string)
     * @param string      $quantity               the quantity (as a string)
     * @param string      $entryCommissionAmount  the entry commission amount (as a string)
     * @param string|null $initialStopLossPrice   the initial stop loss price (as a string)
     * @param string|null $initialTakeProfitPrice the initial take profit price (as a string)
     * @param array|null  $enterTags              an array of tags for the entry signal
     */
    public function __construct(
        public string $positionId,
        public string $symbol,
        public DirectionEnum $direction,
        public string $entryPrice,
        public string $quantity,
        public \DateTimeImmutable $entryTime,
        public string $entryCommissionAmount = '0.0',
        public ?string $initialStopLossPrice = null,
        public ?string $initialTakeProfitPrice = null,
        public ?array $enterTags = null
    ) {
    }
}
