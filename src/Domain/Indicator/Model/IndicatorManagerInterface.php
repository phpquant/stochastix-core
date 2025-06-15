<?php

namespace Stochastix\Domain\Indicator\Model;

use Stochastix\Domain\Common\Model\Series;

interface IndicatorManagerInterface
{
    public function add(string $key, IndicatorInterface $indicator): self;

    /**
     * Calculates all added indicators.
     * It uses the dataframes provided during its construction.
     */
    public function calculateBatch(): void;

    public function getOutputSeries(string $indicatorKey, string $seriesKey = 'value'): Series;

    /**
     * @return array<string, array<string, array<float|null>>>
     */
    public function getAllOutputDataForSave(): array;

    /**
     * @return IndicatorInterface[]
     */
    public function getAll(): array;
}
