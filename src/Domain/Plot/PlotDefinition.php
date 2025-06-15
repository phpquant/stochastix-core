<?php

namespace Stochastix\Domain\Plot;

final readonly class PlotDefinition
{
    /**
     * @param array<PlotComponentInterface> $plots
     * @param array<PlotComponentInterface> $annotations
     */
    public function __construct(
        public string $name,
        public bool $overlay,
        public array $plots,
        public array $annotations,
        public ?string $indicatorKey = null,
    ) {
    }
}
