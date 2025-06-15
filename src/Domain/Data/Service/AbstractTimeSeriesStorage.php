<?php

namespace Stochastix\Domain\Data\Service;

use Stochastix\Domain\Data\Exception\StorageException;

abstract readonly class AbstractTimeSeriesStorage implements TimeSeriesStorageInterface
{
    private const int FORMAT_VERSION = 1;
    private const int HEADER_LENGTH = 64;
    private const int SERIES_DIRECTORY_ENTRY_LENGTH = 64;
    private const int MAX_KEY_LENGTH = 32;

    abstract protected function getMagicNumber(): string;

    abstract protected function getFileExtension(): string;

    abstract protected function getEntityName(): string;

    abstract protected function getPrimaryKeyName(): string;

    public function getFilePath(string $backtestStoragePath, string $runId): string
    {
        return rtrim($backtestStoragePath, '/') . '/' . $runId . $this->getFileExtension();
    }

    public function write(string $filePath, array $timestamps, array $data): void
    {
        $this->ensureDirectoryExists(dirname($filePath));
        $handle = @fopen($filePath, 'wb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for writing.");
        }

        $seriesCount = 0;
        $directoryEntries = [];
        $dataBlocks = [];

        foreach ($data as $primaryKey => $seriesMap) {
            $this->validateKeyLength($primaryKey, "{$this->getEntityName()} key");
            foreach ($seriesMap as $seriesKey => $values) {
                $this->validateKeyLength($seriesKey, 'Series key');
                $directoryEntries[] = ['primaryKey' => $primaryKey, 'seriesKey' => $seriesKey];
                $dataBlocks[] = $values;
                ++$seriesCount;
            }
        }

        $header = pack(
            'a8nCxNJ',
            $this->getMagicNumber(),
            self::FORMAT_VERSION,
            1, // Value Format Code (C)
            // 'x' for the padding byte
            $seriesCount, // Series Count (N)
            count($timestamps) // Timestamp Count (J)
        );
        fwrite($handle, $header);
        fwrite($handle, str_repeat("\0", self::HEADER_LENGTH - strlen($header)));
        foreach ($timestamps as $ts) {
            fwrite($handle, pack('J', $ts));
        }
        foreach ($directoryEntries as $entry) {
            fwrite($handle, pack('a32a32', $entry['primaryKey'], $entry['seriesKey']));
        }
        foreach ($dataBlocks as $values) {
            foreach ($values as $value) {
                fwrite($handle, pack('E', $value ?? NAN));
            }
        }
        fclose($handle);
    }

    public function read(string $filePath, bool $readDataBlocks = true): array
    {
        if (!file_exists($filePath)) {
            throw new StorageException("{$this->getEntityName()} file not found: '{$filePath}'.");
        }
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for reading.");
        }

        try {
            $headerBytes = fread($handle, self::HEADER_LENGTH);
            if (strlen($headerBytes) < 24) {
                throw new StorageException('Header is incomplete.');
            }
            $header = unpack('a8magic/nversion/Cvalformat/x/Nseriescount/Jtimestampcount', $headerBytes);
            if ($header['magic'] !== $this->getMagicNumber()) {
                throw new StorageException("Invalid magic number. Not a {$this->getMagicNumber()} file.");
            }

            $tsCount = $header['timestampcount'];
            $seriesCount = $header['seriescount'];

            $timestamps = [];
            if ($readDataBlocks && $tsCount > 0) {
                $bytes = fread($handle, $tsCount * 8);
                $timestamps = array_values(unpack('J*', $bytes));
            } else {
                fseek($handle, $tsCount * 8, SEEK_CUR);
            }

            $directory = [];
            $primaryKeyName = $this->getPrimaryKeyName();
            for ($i = 0; $i < $seriesCount; ++$i) {
                $bytes = fread($handle, self::SERIES_DIRECTORY_ENTRY_LENGTH);
                $entry = unpack('a32primaryKey/a32seriesKey', $bytes);
                $directory[] = [$primaryKeyName => rtrim($entry['primaryKey'], "\0"), 'seriesKey' => rtrim($entry['seriesKey'], "\0")];
            }

            $data = [];
            if ($readDataBlocks) {
                foreach ($directory as $entry) {
                    $key = $entry[$primaryKeyName];
                    $seriesKey = $entry['seriesKey'];
                    $bytes = fread($handle, $tsCount * 8);
                    $values = [];
                    foreach (unpack('E*', $bytes) as $idx => $val) {
                        if (!is_nan($val)) {
                            $values[] = ['time' => $timestamps[$idx - 1], 'value' => $val];
                        }
                    }
                    $data[$key][$seriesKey] = $values;
                }
            }
        } finally {
            fclose($handle);
        }

        return ['header' => $header, 'directory' => $directory, 'data' => $data];
    }

    private function validateKeyLength(string $key, string $label): void
    {
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new \InvalidArgumentException("{$label} '{$key}' exceeds the maximum length of " . self::MAX_KEY_LENGTH . ' characters.');
        }
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('Directory "%s" was not created', $directory));
        }
    }
}
