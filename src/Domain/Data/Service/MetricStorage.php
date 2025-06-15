<?php

namespace Stochastix\Domain\Data\Service;

final readonly class MetricStorage extends AbstractTimeSeriesStorage implements MetricStorageInterface
{
    protected function getMagicNumber(): string
    {
        return 'STCHXM01';
    }

    protected function getFileExtension(): string
    {
        return '.stchxm';
    }

    protected function getEntityName(): string
    {
        return 'Metric';
    }

    protected function getPrimaryKeyName(): string
    {
        return 'metricKey';
    }
}
