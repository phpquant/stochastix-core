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
        $tempPath = $finalPath . '.tmp';

        // **THE FIX**: Create the parent directory structure so the service can create files within it.
        vfsStream::create(['market' => ['binance' => ['BTC_USDT' => []]]], $this->vfsRoot);

        $this->exchangeAdapterMock->method('supportsExchange')->willReturn(true);
        $this->exchangeAdapterMock->method('fetchOhlcv')->willReturn((static fn (): \Generator => yield ['ts' => 1])());

        // Configure mocks to interact with the virtual filesystem
        $this->binaryStorageMock->method('getTempFilePath')->willReturn($tempPath);
        $this->binaryStorageMock->expects($this->once()) // Expect only ONE call
        ->method('createFile')
            ->willReturnCallback(function (string $path) {
                // This callback ensures that when the service tries to create the temp file,
                // it actually appears in the virtual filesystem with content, passing the filesize() check.
                file_put_contents($path, str_repeat('a', 128));
            });

        $this->binaryStorageMock->method('appendRecords')->willReturn(1);

        // Assert the correct workflow for a new file
        $this->binaryStorageMock->expects($this->never())->method('mergeAndWrite');
        $this->binaryStorageMock->expects($this->once())->method('atomicRename')
            ->with($tempPath, $finalPath);

        $this->downloader->download(
            'binance',
            'BTC/USDT',
            '1h',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-02')
        );
    }

    public function testDownloadMergesWithExistingFile(): void
    {
        $finalPath = $this->vfsRoot->url() . '/market/binance/BTC_USDT/1h.stchx';
        $tempPath = $finalPath . '.tmp';
        $mergedPath = $finalPath . '.merged.tmp';

        // **THE FIX**: Create the directory and the pre-existing file with content directly.
        vfsStream::create([
            'market' => [
                'binance' => [
                    'BTC_USDT' => [
                        '1h.stchx' => str_repeat('a', 128), // File size > 64
                    ],
                ],
            ],
        ], $this->vfsRoot);

        $this->exchangeAdapterMock->method('supportsExchange')->willReturn(true);
        $this->exchangeAdapterMock->method('fetchOhlcv')->willReturn((static fn (): \Generator => yield ['ts' => 1])());
        $this->binaryStorageMock->method('getTempFilePath')->willReturn($tempPath);
        $this->binaryStorageMock->method('getMergedTempFilePath')->willReturn($mergedPath);

        // The downloader will still create a temp file for the new data.
        $this->binaryStorageMock->method('createFile')
            ->willReturnCallback(fn (string $path) => file_put_contents($path, str_repeat('b', 128)));
        $this->binaryStorageMock->method('appendRecords')->willReturn(1);

        // Assert the correct workflow for a merge
        $this->binaryStorageMock->expects($this->once())->method('mergeAndWrite');
        $this->binaryStorageMock->expects($this->once())->method('atomicRename')
            ->with($mergedPath, $finalPath);

        $this->downloader->download(
            'binance',
            'BTC/USDT',
            '1h',
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-02')
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
