<?php

namespace Stochastix\Domain\Data\Service;

interface TimeSeriesStorageInterface
{
    /**
     * Gets the full, absolute path for a data file.
     */
    public function getFilePath(string $backtestStoragePath, string $runId): string;

    /**
     * Writes time-series data to the specified binary file.
     *
     * @param array<string, array<string, array<float|null>>> $data
     */
    public function write(string $filePath, array $timestamps, array $data): void;

    /**
     * Reads a time-series data file.
     *
     * @return array the parsed file contents
     */
    public function read(string $filePath, bool $readDataBlocks = true): array;
}
