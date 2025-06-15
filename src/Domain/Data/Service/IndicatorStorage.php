<?php

namespace Stochastix\Domain\Data\Service;

final readonly class IndicatorStorage extends AbstractTimeSeriesStorage implements IndicatorStorageInterface
{
    protected function getMagicNumber(): string
    {
        return 'STCHXI01';
    }

    protected function getFileExtension(): string
    {
        return '.stchxi';
    }

    protected function getEntityName(): string
    {
        return 'Indicator';
    }

    protected function getPrimaryKeyName(): string
    {
        return 'indicatorKey';
    }
}
