<?php

namespace Stochastix\Domain\Plot\Series;

use Stochastix\Domain\Plot\PlotComponentInterface;

final readonly class Histogram implements PlotComponentInterface
{
    public function __construct(
        public string $key,
        public ?string $color = null,
    ) {
    }

    public function getType(): string
    {
        return 'histogram';
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
