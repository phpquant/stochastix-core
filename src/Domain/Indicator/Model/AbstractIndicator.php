<?php

namespace Stochastix\Domain\Indicator\Model;

use Stochastix\Domain\Common\Model\Series;
use Stochastix\Domain\Plot\PlotDefinition;

abstract class AbstractIndicator implements IndicatorInterface
{
    protected const string DEFAULT_SERIES_KEY = 'value';

    /** @var array<string, Series> */
    protected array $resultSeries = [];

    public function getAllSeries(): array
    {
        if (empty($this->resultSeries)) {
            throw new \LogicException('Indicator not calculated yet. Call calculateBatch first.');
        }

        return $this->resultSeries;
    }

    public function getPlotDefinition(): ?PlotDefinition
    {
        return null;
    }
}
