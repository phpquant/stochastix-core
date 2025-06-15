<?php

namespace Stochastix\Domain\Plot\Annotation;

use Stochastix\Domain\Plot\Enum\HorizontalLineStyleEnum;
use Stochastix\Domain\Plot\PlotComponentInterface;

final readonly class HorizontalLine implements PlotComponentInterface
{
    public function __construct(
        public float $value,
        public string $color = '#787b86',
        public HorizontalLineStyleEnum $style = HorizontalLineStyleEnum::Solid,
        public int $width = 1,
    ) {
    }

    public function getType(): string
    {
        return 'horizontal_line';
    }

    public function toArray(): array
    {
        return [
            'type' => $this->getType(),
            'value' => $this->value,
            'color' => $this->color,
            'style' => $this->style->value,
            'width' => $this->width,
        ];
    }
}
