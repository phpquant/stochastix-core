<?php

namespace Stochastix\Domain\Indicator\Model;

use Ds\Map;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Model\Series;

final class IndicatorManager implements IndicatorManagerInterface
{
    /** @var Map<string, IndicatorInterface> */
    private Map $indicators;

    /** @var Map<string, array<string, Series>> */
    private Map $results;

    public function __construct(
        private readonly BacktestCursor $cursor,
        private readonly Map $dataframes
    ) {
        $this->indicators = new Map();
        $this->results = new Map();
    }

    public function add(string $key, IndicatorInterface $indicator): self
    {
        if ($this->indicators->hasKey($key)) {
            throw new \InvalidArgumentException("Indicator with key '{$key}' already added.");
        }
        $this->indicators->put($key, $indicator);

        return $this;
    }

    public function calculateBatch(): void
    {
        foreach ($this->indicators as $key => $indicator) {
            $indicator->calculateBatch($this->dataframes);

            $allSeries = $indicator->getAllSeries();
            foreach ($allSeries as $series) {
                $series->setCursor($this->cursor);
            }
            $this->results->put($key, $allSeries);
        }
    }

    public function getOutputSeries(string $indicatorKey, string $seriesKey = 'value'): Series
    {
        if (!$this->results->hasKey($indicatorKey) || !isset($this->results->get($indicatorKey)[$seriesKey])) {
            throw new \InvalidArgumentException("No calculated series found for key '{$indicatorKey}' with series key '{$seriesKey}'.");
        }

        return $this->results->get($indicatorKey)[$seriesKey];
    }

    public function getAllOutputDataForSave(): array
    {
        $output = [];
        foreach ($this->results as $indicatorKey => $seriesMap) {
            $output[$indicatorKey] = [];
            foreach ($seriesMap as $seriesKey => $series) {
                $output[$indicatorKey][$seriesKey] = $series->getVector()->toArray();
            }
        }

        return $output;
    }

    public function getAll(): array
    {
        return $this->indicators->values()->toArray();
    }
}
