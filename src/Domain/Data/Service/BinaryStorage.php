<?php

namespace Stochastix\Domain\Data\Service;

use Stochastix\Domain\Data\Exception\StorageException;

final readonly class BinaryStorage implements BinaryStorageInterface
{
    private const string MAGIC_NUMBER = 'STCHXBF1';
    private const int FORMAT_VERSION = 1;
    // Make HEADER_LENGTH_V1 public for easier access in Downloader
    public const int HEADER_LENGTH_V1 = 64;
    private const int RECORD_LENGTH_V1 = 48;
    private const int TIMESTAMP_FORMAT_V1 = 1;
    private const int OHLCV_FORMAT_V1 = 1;

    private const string HEADER_PACK_FORMAT = 'a8n3C2Ja16a4x20';
    private const string HEADER_UNPACK_FORMAT = 'a8magic/nversion/nheaderLength/nrecordLength/CtsFormat/CohlcvFormat/JnumRecords/a16symbol/a4timeframe/x20reserved';
    private const string RECORD_PACK_FORMAT = 'JE5';
    private const string RECORD_UNPACK_FORMAT = 'Jtimestamp/Eopen/Ehigh/Elow/Eclose/Evolume';
    private const string UINT64_PACK_FORMAT = 'J';

    /**
     * Generates the standard temporary file path.
     */
    public function getTempFilePath(string $finalPath): string
    {
        return $finalPath . '.tmp';
    }

    /**
     * Generates the merged temporary file path.
     */
    public function getMergedTempFilePath(string $finalPath): string
    {
        return $finalPath . '.merged.tmp';
    }

    /**
     * Performs an atomic rename operation, replacing the destination if it exists.
     *
     * @throws StorageException
     */
    public function atomicRename(string $sourcePath, string $destinationPath): void
    {
        $this->ensureDirectoryExists(dirname($destinationPath));

        if (!file_exists($sourcePath)) {
            throw new StorageException("Source file '{$sourcePath}' does not exist for renaming.");
        }

        // On Windows, rename might fail if the destination exists. Try deleting first.
        if (file_exists($destinationPath)) {
            if (!unlink($destinationPath)) {
                throw new StorageException("Could not remove existing destination '{$destinationPath}' before renaming.");
            }
        }

        if (!rename($sourcePath, $destinationPath)) {
            // Attempt a copy-delete if rename fails (e.g., across filesystems)
            if (copy($sourcePath, $destinationPath)) {
                unlink($sourcePath);
            } else {
                throw new StorageException("Failed to atomically rename '{$sourcePath}' to '{$destinationPath}'.");
            }
        }
    }

    public function createFile(string $filePath, string $symbol, string $timeframe): void
    {
        $this->ensureDirectoryExists(dirname($filePath));

        $handle = @fopen($filePath, 'wb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for writing.");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        try {
            $headerData = [
                self::MAGIC_NUMBER,
                self::FORMAT_VERSION,
                self::HEADER_LENGTH_V1,
                self::RECORD_LENGTH_V1,
                self::TIMESTAMP_FORMAT_V1,
                self::OHLCV_FORMAT_V1,
                0,
                $symbol,
                $timeframe,
            ];

            $packedHeader = pack(self::HEADER_PACK_FORMAT, ...$headerData);

            if (fwrite($handle, $packedHeader) !== self::HEADER_LENGTH_V1) {
                throw new StorageException("Failed to write complete header to '{$filePath}'.");
            }
            // Flush header immediately
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function appendRecords(string $filePath, iterable $records): int
    {
        $handle = @fopen($filePath, 'ab');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for appending.");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        $writtenCount = 0;
        try {
            foreach ($records as $record) {
                $packedRecord = pack(
                    self::RECORD_PACK_FORMAT,
                    $record['timestamp'],
                    $record['open'],
                    $record['high'],
                    $record['low'],
                    $record['close'],
                    $record['volume']
                );

                if (fwrite($handle, $packedRecord) !== self::RECORD_LENGTH_V1) {
                    throw new StorageException("Failed to write complete record to '{$filePath}'.");
                }

                // **********************************
                // *** FORCE WRITE TO DISK NOW! ***
                fflush($handle);
                // **********************************

                ++$writtenCount;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $writtenCount;
    }

    public function updateRecordCount(string $filePath, int $recordCount): void
    {
        $handle = @fopen($filePath, 'r+b');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for updating record count.");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on '{$filePath}'.");
        }

        try {
            if (fseek($handle, 16) !== 0) { // Offset 16 is where numRecords starts
                throw new StorageException("Could not seek to record count position in '{$filePath}'.");
            }

            $packedCount = pack(self::UINT64_PACK_FORMAT, $recordCount);

            if (fwrite($handle, $packedCount) !== 8) { // 8 bytes for uint64_t
                throw new StorageException("Failed to write record count to '{$filePath}'.");
            }
            // Flush count update immediately
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function readHeader(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new StorageException("File not found for reading header: '{$filePath}'.");
        }
        if (filesize($filePath) < self::HEADER_LENGTH_V1) {
            throw new StorageException("File '{$filePath}' is smaller than the minimum header size.");
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for reading header.");
        }

        try {
            $headerBytes = @fread($handle, self::HEADER_LENGTH_V1);
            if ($headerBytes === false || strlen($headerBytes) !== self::HEADER_LENGTH_V1) {
                throw new StorageException("Could not read complete header from '{$filePath}'.");
            }

            $header = unpack(self::HEADER_UNPACK_FORMAT, $headerBytes);
            if ($header === false) {
                throw new StorageException("Could not unpack header from '{$filePath}'.");
            }

            $header['magic'] = rtrim($header['magic'], "\0");
            $header['symbol'] = rtrim($header['symbol'], "\0");
            $header['timeframe'] = rtrim($header['timeframe'], "\0");

            if ($header['magic'] !== self::MAGIC_NUMBER) {
                throw new StorageException("Invalid magic number. Not an STCHXBF1 file: '{$filePath}'.");
            }
            if ($header['version'] !== self::FORMAT_VERSION) {
                throw new StorageException("Unsupported format version '{$header['version']}' in '{$filePath}'.");
            }
            if ($header['headerLength'] !== self::HEADER_LENGTH_V1) {
                throw new StorageException("Invalid header length '{$header['headerLength']}' in '{$filePath}'.");
            }
            if ($header['recordLength'] !== self::RECORD_LENGTH_V1) {
                throw new StorageException("Invalid record length '{$header['recordLength']}' in '{$filePath}'.");
            }

            return $header;
        } finally {
            fclose($handle);
        }
    }

    public function readRecordByIndex(string $filePath, int $index): ?array
    {
        $header = $this->readHeader($filePath);

        if ($index < 0 || $index >= $header['numRecords']) {
            return null;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for reading by index.");
        }

        try {
            $offset = $header['headerLength'] + ($index * $header['recordLength']);

            if (fseek($handle, $offset) !== 0) {
                throw new StorageException("Could not seek to index {$index} in '{$filePath}'.");
            }

            $recordBytes = @fread($handle, $header['recordLength']);
            if ($recordBytes === false || strlen($recordBytes) !== $header['recordLength']) {
                throw new StorageException("Could not read record at index {$index} from '{$filePath}'.");
            }

            $record = unpack(self::RECORD_UNPACK_FORMAT, $recordBytes);

            return $record === false ? null : $record;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Reads all records sequentially from the file using a generator.
     * Ensures shared lock for reading and proper resource handling.
     *
     * @throws StorageException
     */
    public function readRecordsSequentially(string $filePath): \Generator
    {
        if (!file_exists($filePath) || filesize($filePath) <= self::HEADER_LENGTH_V1) {
            // If the file doesn't exist or has no records, yield nothing.
            yield from [];

            return;
        }

        $header = $this->readHeader($filePath);
        $recordCount = $header['numRecords'];
        $headerLength = $header['headerLength'];
        $recordLength = $header['recordLength'];

        if ($recordCount === 0) {
            yield from [];

            return;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for sequential reading.");
        }

        if (!flock($handle, LOCK_SH)) { // Use shared lock for reading
            fclose($handle);
            throw new StorageException("Could not acquire shared lock on '{$filePath}'.");
        }

        try {
            if (fseek($handle, $headerLength) !== 0) {
                throw new StorageException("Could not seek to data start in '{$filePath}'.");
            }

            for ($i = 0; $i < $recordCount; ++$i) {
                $recordBytes = @fread($handle, $recordLength);

                if ($recordBytes === false || strlen($recordBytes) === 0) {
                    break;
                }

                if (strlen($recordBytes) !== $recordLength) {
                    throw new StorageException("Could not read complete record at index {$i} from '{$filePath}'. Expected {$recordLength} bytes, got " . strlen($recordBytes) . '.');
                }

                $record = unpack(self::RECORD_UNPACK_FORMAT, $recordBytes);
                if ($record === false) {
                    throw new StorageException("Failed to unpack record at index {$i} in '{$filePath}'.");
                }

                yield $record;
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Merges two sorted STCHX files into a new sorted file, overwriting duplicates.
     * It uses the K-way merge algorithm in a streaming fashion.
     *
     * @param string $originalPath path to the existing (potentially empty/non-existent) data file
     * @param string $newDataPath  path to the temporary file containing new data
     * @param string $outputPath   path to write the merged result
     *
     * @return int the total number of records in the merged file
     *
     * @throws StorageException
     */
    public function mergeAndWrite(string $originalPath, string $newDataPath, string $outputPath): int
    {
        $origExists = file_exists($originalPath) && filesize($originalPath) > self::HEADER_LENGTH_V1;
        $newExists = file_exists($newDataPath) && filesize($newDataPath) > self::HEADER_LENGTH_V1;

        if (!$origExists && !$newExists) {
            // If both are missing or empty, create an empty file and return 0.
            $this->createFile($outputPath, 'UNKNOWN', 'N/A'); // Or throw? Let's create empty for now.

            return 0;
        }

        // Determine header info and validate (prioritize new data header if available)
        $header = $newExists ? $this->readHeader($newDataPath) : $this->readHeader($originalPath);
        if ($origExists && $newExists) {
            $newHeader = $this->readHeader($newDataPath);
            if ($header['symbol'] !== $newHeader['symbol'] || $header['timeframe'] !== $newHeader['timeframe']) {
                throw new StorageException("Cannot merge files: Symbol/Timeframe mismatch ('{$header['symbol']}/{$header['timeframe']}' vs '{$newHeader['symbol']}/{$newHeader['timeframe']}').");
            }
            // Use the header from the new data (it's likely the same anyway)
            $header = $newHeader;
        }

        // Create the output file with the determined header
        $this->createFile($outputPath, $header['symbol'], $header['timeframe']);

        $genOrig = $this->readRecordsSequentially($originalPath);
        $genNew = $this->readRecordsSequentially($newDataPath);

        $handle = @fopen($outputPath, 'ab'); // 'ab' - append binary (we already wrote header)
        if ($handle === false) {
            throw new StorageException("Could not open output file '{$outputPath}' for merging.");
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new StorageException("Could not acquire exclusive lock on output file '{$outputPath}'.");
        }

        $recordCount = 0;
        $handleWrapper = null; // Declare to ensure it's in scope for finally

        try {
            $handleWrapper = $handle; // Assign for use in finally

            $recOrig = $genOrig->valid() ? $genOrig->current() : null;
            $recNew = $genNew->valid() ? $genNew->current() : null;
            $lastTimestampWritten = -1;

            while ($recOrig !== null || $recNew !== null) {
                $writeRecord = null;
                $advanceOrig = false;
                $advanceNew = false;

                if ($recOrig !== null && ($recNew === null || $recOrig['timestamp'] < $recNew['timestamp'])) {
                    $writeRecord = $recOrig;
                    $advanceOrig = true;
                } elseif ($recNew !== null && ($recOrig === null || $recNew['timestamp'] < $recOrig['timestamp'])) {
                    $writeRecord = $recNew;
                    $advanceNew = true;
                } elseif ($recOrig !== null && $recNew !== null) { // Timestamps must be equal
                    $writeRecord = $recNew; // New data wins (overwrite)
                    $advanceOrig = true; // Advance both
                    $advanceNew = true;
                } else {
                    break; // Should not happen.
                }

                if ($writeRecord !== null) {
                    // **Crucial De-duplication Check**: Only write if the timestamp is strictly greater
                    // than the last one written. This handles the $recOrig == $recNew case correctly
                    // and prevents writing the same record twice if one file has duplicates.
                    if ($writeRecord['timestamp'] > $lastTimestampWritten) {
                        $packedRecord = pack(
                            self::RECORD_PACK_FORMAT,
                            $writeRecord['timestamp'],
                            $writeRecord['open'],
                            $writeRecord['high'],
                            $writeRecord['low'],
                            $writeRecord['close'],
                            $writeRecord['volume']
                        );

                        if (fwrite($handle, $packedRecord) !== self::RECORD_LENGTH_V1) {
                            throw new StorageException("Failed to write merged record to '{$outputPath}'.");
                        }
                        // **********************************
                        // *** FORCE WRITE TO DISK NOW! ***
                        fflush($handle);
                        // **********************************

                        $lastTimestampWritten = $writeRecord['timestamp'];
                        ++$recordCount;
                    }
                }

                if ($advanceOrig) {
                    $genOrig->next();
                    $recOrig = $genOrig->valid() ? $genOrig->current() : null;
                }
                if ($advanceNew) {
                    $genNew->next();
                    $recNew = $genNew->valid() ? $genNew->current() : null;
                }
            } // End while
        } catch (\Throwable $e) {
            throw new StorageException('Error during merge process: ' . $e->getMessage(), 0, $e);
        } finally {
            // Ensure unlock/close even on error
            if ($handleWrapper) {
                flock($handleWrapper, LOCK_UN);
                fclose($handleWrapper);
            }
        }

        // We re-open to update the header safely after closing and unlocking.
        $this->updateRecordCount($outputPath, $recordCount);

        return $recordCount;
    }

    public function readRecordsByTimestampRange(string $filePath, int $startTimestamp, int $endTimestamp): \Generator
    {
        $header = $this->readHeader($filePath);
        $recordCount = $header['numRecords'];
        $headerLength = $header['headerLength'];
        $recordLength = $header['recordLength'];

        if ($recordCount === 0) {
            return;
        }

        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            throw new StorageException("Could not open file '{$filePath}' for reading range.");
        }

        try {
            $startIndex = $this->findStartIndexByTimestamp($handle, $startTimestamp, $header);

            // If no record is >= startTimestamp, or start is after last record
            if ($startIndex === -1 || $startIndex >= $recordCount) {
                return; // Yield nothing
            }

            $offset = $headerLength + ($startIndex * $recordLength);
            if (fseek($handle, $offset) !== 0) {
                throw new StorageException("Could not seek to start index {$startIndex} in '{$filePath}'.");
            }

            for ($i = $startIndex; $i < $recordCount; ++$i) {
                $recordBytes = @fread($handle, $recordLength);
                if ($recordBytes === false || strlen($recordBytes) !== $recordLength) {
                    break; // End of file or read error
                }

                $record = unpack(self::RECORD_UNPACK_FORMAT, $recordBytes);
                if ($record === false) {
                    throw new StorageException("Failed to unpack record at index {$i} in '{$filePath}'.");
                }

                if ($record['timestamp'] > $endTimestamp) {
                    break; // Exceeded the end timestamp
                }

                // Only yield if within range (binary search finds >= start, so check <= end)
                if ($record['timestamp'] >= $startTimestamp) {
                    yield $record;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function findStartIndexByTimestamp($handle, int $targetTimestamp, array $header): int
    {
        $low = 0;
        $high = $header['numRecords'] - 1;
        $startIndex = -1; // -1 means no suitable index found
        $headerLength = $header['headerLength'];
        $recordLength = $header['recordLength'];

        if ($header['numRecords'] === 0) {
            return -1;
        }

        while ($low <= $high) {
            $mid = $low + (($high - $low) >> 1); // Efficient midpoint calculation

            $offset = $headerLength + ($mid * $recordLength);
            if (fseek($handle, $offset) !== 0) {
                throw new StorageException("Binary search seek failed at index {$mid}.");
            }

            $recordBytes = @fread($handle, 8); // Only need 8 bytes for timestamp
            if ($recordBytes === false || strlen($recordBytes) < 8) {
                // Handle potential read issues or EOF if high was 0
                if ($low === $high) { // If it was the last element, it might be the one or not
                    $high = $low - 1; // Break the loop
                    continue;
                }
                throw new StorageException("Binary search read failed at index {$mid}.");
            }

            $timestamp = unpack('J', $recordBytes)[1] ?? -1;

            if ($timestamp >= $targetTimestamp) {
                $startIndex = $mid; // Found a potential start, try earlier
                $high = $mid - 1;
            } else {
                $low = $mid + 1; // Need to search later
            }
        }

        return $startIndex;
    }

    private function ensureDirectoryExists(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new StorageException(sprintf('Directory "%s" was not created', $directory));
        }
    }
}
