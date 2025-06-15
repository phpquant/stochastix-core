<?php

namespace Stochastix\Domain\Backtesting\Dto;

final readonly class OhlcvDto
{
    public function __construct(
        public int $timestamp,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume
    ) {
    }
}
