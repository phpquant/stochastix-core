<?php

namespace Stochastix\Tests\Domain\Data\Service;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Exception\DownloaderException;
use Stochastix\Domain\Data\Exception\ExchangeException;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Stochastix\Domain\Data\Service\Exchange\ExchangeAdapterInterface;
use Stochastix\Domain\Data\Service\OhlcvDownloader;

class OhlcvDownloaderTest extends TestCase
{
    private ExchangeAdapterInterface $exchangeAdapterMock;
    private BinaryStorageInterface $binaryStorageMock;
    private LoggerInterface $loggerMock;
    private vfsStreamDirectory $vfsRoot;
    private OhlcvDownloader $downloader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exchangeAdapterMock = $this->createMock(ExchangeAdapterInterface::class);
        $this->binaryStorageMock = $this->createMock(BinaryStorageInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->vfsRoot = vfsStream::setup('data');

        $this->downloader = new OhlcvDownloader(
            $this->exchangeAdapterMock,
            $this->binaryStorageMock,
            $this->vfsRoot->url() . '/market',
            $this->loggerMock
        );
    }

    public function testDownloadForNewFile(): void
    {
        $finalPath = $this->vfsRoot->url() . '/market/binance/BTC_USDT/1h.stchx';

        vfsStream::create(['market' => ['binance' => ['BTC_USDT' => []]]], $this->vfsRoot);

        $this->exchangeAdapterMock->method('supportsExchange')->willReturn(true);
        $this->exchangeAdapterMock->method('fetchOhlcv')->willReturn((static fn (): \Generator => yield ['ts' => 1])());

        $this->binaryStorageMock->method('getTempFilePath')->willReturn($finalPath . '.tmp');
        $this->binaryStorageMock->method('getMergedTempFilePath')->willReturn($finalPath . '.merged.tmp');

        $this->binaryStorageMock->expects($this->once())
            ->method('createFile')
            ->willReturnCallback(fn (string $path) => file_put_contents($path, str_repeat('a', 128)));

        $this->binaryStorageMock->method('appendRecords')->willReturn(1);

        $this->binaryStorageMock->expects($this->exactly(2))->method('atomicRename');

        $this->downloader->download(
            'binance',
            'BTC/USDT',
            '1h',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-02')
        );
    }

    public function testDownloadWithForceOverwriteMergesWithExistingFile(): void
    {
        $finalPath = $this->vfsRoot->url() . '/market/binance/BTC_USDT/1h.stchx';
        $tempPath = $finalPath . '.tmp';
        $mergedPath = $finalPath . '.merged.tmp';

        vfsStream::create(['market' => ['binance' => ['BTC_USDT' => ['1h.stchx' => str_repeat('a', 128)]]]], $this->vfsRoot);

        $this->exchangeAdapterMock->method('supportsExchange')->willReturn(true);
        $this->exchangeAdapterMock->method('fetchOhlcv')->willReturn((static fn (): \Generator => yield ['ts' => 1])());
        $this->binaryStorageMock->method('getTempFilePath')->willReturn($tempPath);
        $this->binaryStorageMock->method('getMergedTempFilePath')->willReturn($mergedPath);
        $this->binaryStorageMock->method('createFile')->willReturnCallback(fn (string $path) => file_put_contents($path, str_repeat('b', 128)));
        $this->binaryStorageMock->method('appendRecords')->willReturn(1);
        $this->binaryStorageMock->expects($this->once())->method('mergeAndWrite');

        $this->binaryStorageMock->expects($this->once())->method('atomicRename')
            ->with($this->stringEndsWith('.merged.tmp.0'), $finalPath);

        $this->downloader->download(
            'binance',
            'BTC/USDT',
            '1h',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-02'),
            true // Force overwrite
        );
    }

    public function testDownloadFillsInternalGaps(): void
    {
        $finalPath = $this->vfsRoot->url() . '/market/binance/BTC_USDT/1h.stchx';
        vfsStream::create(['market' => ['binance' => ['BTC_USDT' => ['1h.stchx' => str_repeat('a', 128)]]]], $this->vfsRoot);

        // --- Mocks for Gap Detection ---
        $this->binaryStorageMock->method('readHeader')->willReturn(['numRecords' => 2]);
        $this->binaryStorageMock->method('readRecordByIndex')
            ->willReturnOnConsecutiveCalls(
                ['timestamp' => strtotime('2024-01-01 10:00:00')], // Local start
                ['timestamp' => strtotime('2024-01-01 12:00:00')]  // Local end (note the 1-hour gap)
            );
        $this->binaryStorageMock->method('readRecordsSequentially')
            ->willReturn((static fn () => yield from [
                ['timestamp' => strtotime('2024-01-01 10:00:00')],
                ['timestamp' => strtotime('2024-01-01 12:00:00')],
            ])());

        // --- Mocks for Downloading the Gap ---
        $this->exchangeAdapterMock->method('supportsExchange')->willReturn(true);
        $this->exchangeAdapterMock->expects($this->once())
            ->method('fetchOhlcv')
            ->with(
                $this->anything(),
                $this->anything(),
                '1h',
                $this->equalTo(new \DateTimeImmutable('2024-01-01 11:00:00')),
                $this->equalTo(new \DateTimeImmutable('2024-01-01 11:59:59'))
            )
            ->willReturn((static fn (): \Generator => yield ['timestamp' => strtotime('2024-01-01 11:00:00')])());

        $this->binaryStorageMock->method('getTempFilePath')->willReturn($finalPath . '.tmp');
        $this->binaryStorageMock->method('getMergedTempFilePath')->willReturn($finalPath . '.merged.tmp');

        $this->binaryStorageMock->method('createFile')
            ->willReturnCallback(fn (string $path) => file_put_contents($path, str_repeat('a', 128)));

        $this->binaryStorageMock->method('appendRecords')->willReturn(1);
        $this->binaryStorageMock->expects($this->once())->method('mergeAndWrite');

        // --- Execute ---
        $this->downloader->download(
            'binance',
            'BTC/USDT',
            '1h',
            new \DateTimeImmutable('2024-01-01 10:00:00'),
            new \DateTimeImmutable('2024-01-01 12:00:00'),
            false
        );
    }

    public function testDownloadHandlesExchangeException(): void
    {
        $this->exchangeAdapterMock->method('supportsExchange')->willReturn(true);
        $this->exchangeAdapterMock->method('fetchOhlcv')->willThrowException(new ExchangeException('API limit reached'));

        $this->expectException(DownloaderException::class);
        $this->expectExceptionMessage('Download failed for binance/BTC/USDT: API limit reached');

        $this->downloader->download(
            'binance',
            'BTC/USDT',
            '1h',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-02')
        );
    }
}
