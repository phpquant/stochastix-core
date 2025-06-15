<?php

namespace Stochastix\Domain\Indicator\Model;

use Ds\Map;
use Stochastix\Domain\Common\Model\Series;
use Stochastix\Domain\Plot\PlotDefinition;

interface IndicatorInterface
{
    public function calculateBatch(Map $dataframes): void;

    /**
     * @return array<string, Series>
     */
    public function getAllSeries(): array;

    /**
     * Returns the plot definition for this indicator, if it's plottable.
     */
    public function getPlotDefinition(): ?PlotDefinition;
}
