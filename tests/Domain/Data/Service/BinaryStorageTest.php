<?php

namespace Stochastix\Tests\Domain\Data\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Data\Exception\StorageException;
use Stochastix\Domain\Data\Service\BinaryStorage;
use Symfony\Component\Filesystem\Filesystem;

class BinaryStorageTest extends TestCase
{
    private BinaryStorage $binaryStorage;
    private string $tempDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->binaryStorage = new BinaryStorage();
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/stochastix_test_' . uniqid();
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->filesystem->remove($this->tempDir);
    }

    private function createSampleRecords(int $count, int $startTime = 1704067200, int $interval = 60): array
    {
        $records = [];
        $time = $startTime;
        for ($i = 0; $i < $count; ++$i) {
            $records[] = [
                'timestamp' => $time,
                'open' => 100.0 + $i,
                'high' => 105.0 + $i,
                'low' => 95.0 + $i,
                'close' => 102.0 + $i,
                'volume' => 1000.0 + ($i * 10),
            ];
            $time += $interval;
        }

        return $records;
    }

    public function testCreateFileAndReadHeader(): void
    {
        $filePath = $this->tempDir . '/test.stchx';
        $symbol = 'TEST/USDT';
        $timeframe = '1m';

        $this->binaryStorage->createFile($filePath, $symbol, $timeframe);

        self::assertFileExists($filePath);

        $header = $this->binaryStorage->readHeader($filePath);

        self::assertSame('STCHXBF1', $header['magic']);
        self::assertSame(1, $header['version']);
        self::assertSame(64, $header['headerLength']);
        self::assertSame(48, $header['recordLength']);
        self::assertSame($symbol, $header['symbol']);
        self::assertSame($timeframe, $header['timeframe']);
        self::assertSame(0, $header['numRecords']);
    }

    public function testAppendUpdateAndReadRecords(): void
    {
        $filePath = $this->tempDir . '/append_test.stchx';
        $symbol = 'APPEND/USDT';
        $timeframe = '5m';
        $records = $this->createSampleRecords(10);

        $this->binaryStorage->createFile($filePath, $symbol, $timeframe);
        $writtenCount = $this->binaryStorage->appendRecords($filePath, $records);
        $this->binaryStorage->updateRecordCount($filePath, $writtenCount);

        self::assertSame(10, $writtenCount);

        // Test read header after update
        $header = $this->binaryStorage->readHeader($filePath);
        self::assertSame(10, $header['numRecords']);

        // Test read by index
        $record5 = $this->binaryStorage->readRecordByIndex($filePath, 5);
        self::assertNotNull($record5);
        self::assertSame($records[5]['timestamp'], $record5['timestamp']);
        self::assertEqualsWithDelta($records[5]['close'], $record5['close'], 0.0001);

        // Test read sequentially
        $readRecords = iterator_to_array($this->binaryStorage->readRecordsSequentially($filePath));
        self::assertCount(10, $readRecords);
        self::assertSame($records[8]['timestamp'], $readRecords[8]['timestamp']);
        self::assertEqualsWithDelta($records[8]['high'], $readRecords[8]['high'], 0.0001);
    }

    public function testReadByInvalidIndexReturnsNull(): void
    {
        $filePath = $this->tempDir . '/index_test.stchx';
        $this->binaryStorage->createFile($filePath, 'TEST/IX', '1d');
        $records = $this->createSampleRecords(5);
        $this->binaryStorage->appendRecords($filePath, $records);
        $this->binaryStorage->updateRecordCount($filePath, 5);

        self::assertNull($this->binaryStorage->readRecordByIndex($filePath, 10)); // Index out of bounds
        self::assertNull($this->binaryStorage->readRecordByIndex($filePath, -1)); // Negative index
    }

    public function testReadRecordsByTimestampRange(): void
    {
        $filePath = $this->tempDir . '/range_test.stchx';
        // Creates records at 1704067200, 1704067260, 1704067320, 1704067380, 1704067440
        $records = $this->createSampleRecords(5, 1704067200, 60);

        $this->binaryStorage->createFile($filePath, 'RANGE/USDT', '1m');
        $this->binaryStorage->appendRecords($filePath, $records);
        $this->binaryStorage->updateRecordCount($filePath, 5);

        // Range covering middle 3 records
        $rangeRecords = iterator_to_array($this->binaryStorage->readRecordsByTimestampRange($filePath, 1704067260, 1704067380));
        self::assertCount(3, $rangeRecords);
        self::assertSame(1704067260, $rangeRecords[0]['timestamp']);
        self::assertSame(1704067380, $rangeRecords[2]['timestamp']);

        // Range covering from start
        $rangeRecordsStart = iterator_to_array($this->binaryStorage->readRecordsByTimestampRange($filePath, 1704067200, 1704067260));
        self::assertCount(2, $rangeRecordsStart);

        // Range covering to end
        $rangeRecordsEnd = iterator_to_array($this->binaryStorage->readRecordsByTimestampRange($filePath, 1704067380, 9999999999));
        self::assertCount(2, $rangeRecordsEnd);

        // Range with no matches
        $rangeRecordsEmpty = iterator_to_array($this->binaryStorage->readRecordsByTimestampRange($filePath, 1600000000, 1600000100));
        self::assertCount(0, $rangeRecordsEmpty);
    }

    public function testMergeWithEmptyOriginal(): void
    {
        $originalPath = $this->tempDir . '/merge_orig_empty.stchx';
        $newDataPath = $this->tempDir . '/merge_new.stchx';
        $outputPath = $this->tempDir . '/merge_out.stchx';

        $newRecords = $this->createSampleRecords(5);
        $this->binaryStorage->createFile($newDataPath, 'MERGE/USDT', '1h');
        $this->binaryStorage->appendRecords($newDataPath, $newRecords);
        $this->binaryStorage->updateRecordCount($newDataPath, 5);

        $mergedCount = $this->binaryStorage->mergeAndWrite($originalPath, $newDataPath, $outputPath);
        self::assertSame(5, $mergedCount);

        $header = $this->binaryStorage->readHeader($outputPath);
        self::assertSame(5, $header['numRecords']);

        $readRecords = iterator_to_array($this->binaryStorage->readRecordsSequentially($outputPath));
        self::assertCount(5, $readRecords);
    }

    public function testMergeWithOverwritingData(): void
    {
        $originalPath = $this->tempDir . '/merge_orig.stchx';
        $newDataPath = $this->tempDir . '/merge_new_overwrite.stchx';
        $outputPath = $this->tempDir . '/merge_out_overwrite.stchx';

        // Original has 5 records starting at T=0
        $originalRecords = $this->createSampleRecords(5, 1704067200, 60);
        $this->binaryStorage->createFile($originalPath, 'MERGE/USDT', '1m');
        $this->binaryStorage->appendRecords($originalPath, $originalRecords);
        $this->binaryStorage->updateRecordCount($originalPath, 5);

        // New data has 3 records, T=2 and T=3 overlap/overwrite, T=6 is new
        $newRecords = [
            $this->createSampleRecords(1, 1704067320, 60)[0], // T=2, overwrites
            $this->createSampleRecords(1, 1704067380, 60)[0], // T=3, overwrites
            $this->createSampleRecords(1, 1704067560, 60)[0], // T=6, new
        ];
        // Modify close prices to check for overwrite
        $newRecords[0]['close'] = 999.0;
        $newRecords[1]['close'] = 888.0;

        $this->binaryStorage->createFile($newDataPath, 'MERGE/USDT', '1m');
        $this->binaryStorage->appendRecords($newDataPath, $newRecords);
        $this->binaryStorage->updateRecordCount($newDataPath, 3);

        $mergedCount = $this->binaryStorage->mergeAndWrite($originalPath, $newDataPath, $outputPath);

        // 5 original + 1 new = 6 total records
        self::assertSame(6, $mergedCount);

        $readRecords = iterator_to_array($this->binaryStorage->readRecordsSequentially($outputPath));
        self::assertCount(6, $readRecords);

        // Check for overwritten values
        self::assertEqualsWithDelta(999.0, $readRecords[2]['close'], 0.0001);
        self::assertEqualsWithDelta(888.0, $readRecords[3]['close'], 0.0001);
        // Check original value that wasn't overwritten
        self::assertEqualsWithDelta(106.0, $readRecords[1]['high'], 0.0001);
        // Check new record
        self::assertSame(1704067560, $readRecords[5]['timestamp']);
    }

    public function testMergeWithDuplicateTimestampsInNewData(): void
    {
        $originalPath = $this->tempDir . '/merge_orig_dupe.stchx';
        $newDataPath = $this->tempDir . '/merge_new_dupe.stchx';
        $outputPath = $this->tempDir . '/merge_out_dupe.stchx';

        $originalRecords = $this->createSampleRecords(2, 1704067200, 60); // T=0, T=1
        $this->binaryStorage->createFile($originalPath, 'MERGE/USDT', '1m');
        $this->binaryStorage->appendRecords($originalPath, $originalRecords);
        $this->binaryStorage->updateRecordCount($originalPath, 2);

        $recordT1 = $this->createSampleRecords(1, 1704067260, 60)[0]; // T=1
        $recordT1['close'] = 777.0; // Mark this as the one we expect to see
        $recordT2 = $this->createSampleRecords(1, 1704067320, 60)[0]; // T=2

        // New data has a duplicate timestamp internally. The merge should handle this gracefully.
        $newRecords = [$recordT1, $recordT1, $recordT2];
        $this->binaryStorage->createFile($newDataPath, 'MERGE/USDT', '1m');
        $this->binaryStorage->appendRecords($newDataPath, $newRecords);
        $this->binaryStorage->updateRecordCount($newDataPath, 3);

        $mergedCount = $this->binaryStorage->mergeAndWrite($originalPath, $newDataPath, $outputPath);

        // Expected: T=0 (orig), T=1 (new, de-duped), T=2 (new) -> 3 records
        self::assertSame(3, $mergedCount);
        $readRecords = iterator_to_array($this->binaryStorage->readRecordsSequentially($outputPath));
        self::assertCount(3, $readRecords);
        self::assertEqualsWithDelta(777.0, $readRecords[1]['close'], 0.0001);
    }

    public function testReadHeaderInvalidFileThrowsException(): void
    {
        $filePath = $this->tempDir . '/invalid.stchx';

        // Test with a non-existent file
        $this->expectException(StorageException::class);
        $this->binaryStorage->readHeader($filePath);
    }

    public function testReadHeaderWrongMagicNumberThrowsException(): void
    {
        $filePath = $this->tempDir . '/invalid_magic.stchx';
        file_put_contents($filePath, str_repeat('A', 64)); // Write 64 bytes of junk

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Invalid magic number');
        $this->binaryStorage->readHeader($filePath);
    }
}
