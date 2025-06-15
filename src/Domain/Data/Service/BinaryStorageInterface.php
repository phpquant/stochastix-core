<?php

namespace Stochastix\Domain\Data\Service;

interface BinaryStorageInterface
{
    public function getTempFilePath(string $finalPath): string;

    public function getMergedTempFilePath(string $finalPath): string;

    public function atomicRename(string $sourcePath, string $destinationPath): void;

    public function createFile(string $filePath, string $symbol, string $timeframe): void;

    public function appendRecords(string $filePath, iterable $records): int;

    public function updateRecordCount(string $filePath, int $recordCount): void;

    public function readHeader(string $filePath): array;

    public function readRecordByIndex(string $filePath, int $index): ?array;

    public function readRecordsSequentially(string $filePath): \Generator;

    public function mergeAndWrite(string $originalPath, string $newDataPath, string $outputPath): int;

    public function readRecordsByTimestampRange(string $filePath, int $startTimestamp, int $endTimestamp): \Generator;
}
