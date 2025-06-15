<?php

namespace Stochastix\Tests\Domain\Data\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Data\Exception\StorageException;
use Stochastix\Domain\Data\Service\MetricStorage;
use Symfony\Component\Filesystem\Filesystem;

class MetricStorageTest extends TestCase
{
    private MetricStorage $metricStorage;
    private string $tempDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->metricStorage = new MetricStorage();
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/stochastix_metric_storage_test_' . uniqid();
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->filesystem->remove($this->tempDir);
    }

    private function createSampleTimestamps(int $count, int $startTime = 1704067200, int $interval = 86400): array
    {
        $timestamps = [];
        $time = $startTime;
        for ($i = 0; $i < $count; ++$i) {
            $timestamps[] = $time;
            $time += $interval;
        }

        return $timestamps;
    }

    public function testWriteAndReadAllData(): void
    {
        $filePath = $this->metricStorage->getFilePath($this->tempDir, 'test_run_1');
        $timestamps = $this->createSampleTimestamps(5);

        $metricData = [
            'equity' => ['value' => [1000.0, 1010.5, 998.0, null, 1025.25]],
            'alpha' => ['value' => [0.001, -0.0005, 0.002, 0.0015, 0.0018]],
        ];

        $this->metricStorage->write($filePath, $timestamps, $metricData);
        self::assertFileExists($filePath);

        $readResult = $this->metricStorage->read($filePath);

        self::assertSame('STCHXM01', $readResult['header']['magic']);
        self::assertSame('equity', $readResult['directory'][0]['metricKey']);
        self::assertSame('alpha', $readResult['directory'][1]['metricKey']);

        $expectedEquityData = [
            ['time' => $timestamps[0], 'value' => 1000.0],
            ['time' => $timestamps[1], 'value' => 1010.5],
            ['time' => $timestamps[2], 'value' => 998.0],
            ['time' => $timestamps[4], 'value' => 1025.25],
        ];

        self::assertEquals($expectedEquityData, $readResult['data']['equity']['value']);
        self::assertEquals(0.0018, $readResult['data']['alpha']['value'][4]['value']);
    }

    public function testReadThrowsExceptionForNonExistentFile(): void
    {
        $filePath = $this->metricStorage->getFilePath($this->tempDir, 'non_existent');
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Metric file not found: '{$filePath}'.");
        $this->metricStorage->read($filePath);
    }
}
