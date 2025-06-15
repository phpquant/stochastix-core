<?php

namespace Stochastix\Tests\Domain\Data\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Data\Exception\StorageException;
use Stochastix\Domain\Data\Service\IndicatorStorage;
use Symfony\Component\Filesystem\Filesystem;

class IndicatorStorageTest extends TestCase
{
    private IndicatorStorage $indicatorStorage;
    private string $tempDir;
    private Filesystem $filesystem;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indicatorStorage = new IndicatorStorage();
        $this->filesystem = new Filesystem();
        $this->tempDir = sys_get_temp_dir() . '/stochastix_indicator_test_' . uniqid();
        $this->filesystem->mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->filesystem->remove($this->tempDir);
    }

    private function createSampleTimestamps(int $count, int $startTime = 1704067200, int $interval = 60): array
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
        $filePath = $this->indicatorStorage->getFilePath($this->tempDir, 'test_run_1');
        $timestamps = $this->createSampleTimestamps(5);

        $indicatorData = [
            'ema_short' => ['value' => [10.1, 10.2, 10.3, null, 10.5]],
            'macd' => [
                'macd' => [1.1, 1.2, 1.3, 1.4, 1.5],
                'signal' => [0.9, 1.0, 1.1, 1.2, 1.3],
                'hist' => [0.2, 0.2, 0.2, 0.2, 0.2],
            ],
        ];

        $this->indicatorStorage->write($filePath, $timestamps, $indicatorData);
        self::assertFileExists($filePath);

        $readResult = $this->indicatorStorage->read($filePath);

        self::assertSame('STCHXI01', $readResult['header']['magic']);
        self::assertSame('ema_short', $readResult['directory'][0]['indicatorKey']);
        self::assertSame('macd', $readResult['directory'][1]['indicatorKey']);
    }

    public function testReadThrowsExceptionForNonExistentFile(): void
    {
        $filePath = $this->indicatorStorage->getFilePath($this->tempDir, 'non_existent_run');
        $this->expectException(StorageException::class);
        $this->expectExceptionMessage("Indicator file not found: '{$filePath}'.");
        $this->indicatorStorage->read($filePath);
    }
}
