<?php

namespace Stochastix\Domain\Order\Dto;

final readonly class PendingOrder
{
    public function __construct(
        public OrderSignal $signal,
        public int $creationBarIndex
    ) {
    }
}
