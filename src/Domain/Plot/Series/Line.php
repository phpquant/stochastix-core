<?php

namespace Stochastix\Domain\Plot\Series;

use Stochastix\Domain\Plot\PlotComponentInterface;

final readonly class Line implements PlotComponentInterface
{
    public function __construct(
        public string $key = 'value',
        public ?string $color = null,
    ) {
    }

    public function getType(): string
    {
        return 'line';
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'type' => $this->getType(),
            'color' => $this->color,
        ];
    }
}
