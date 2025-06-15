<?php

namespace Stochastix\Domain\Plot;

interface PlotComponentInterface
{
    public function getType(): string;

    public function toArray(): array;
}
