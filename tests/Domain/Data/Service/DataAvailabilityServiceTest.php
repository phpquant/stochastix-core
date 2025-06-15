<?php

namespace Stochastix\Tests\Domain\Data\Service;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Exception\StorageException;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Stochastix\Domain\Data\Service\DataAvailabilityService;

class DataAvailabilityServiceTest extends TestCase
{
    private BinaryStorageInterface $binaryStorageMock;
    private LoggerInterface $loggerMock;
    private vfsStreamDirectory $vfsRoot;
    private DataAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->binaryStorageMock = $this->createMock(BinaryStorageInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->vfsRoot = vfsStream::setup('market_data');

        $this->service = new DataAvailabilityService(
            $this->vfsRoot->url(),
            $this->binaryStorageMock,
            $this->loggerMock
        );
    }

    public function testGetManifest(): void
    {
        // 1. Setup virtual file structure
        $structure = [
            'binance' => [
                'BTC_USDT' => ['1h.stchx' => '', '4h.stchx' => ''],
                'ETH_USDT' => ['1d.stchx' => ''],
            ],
            'okx' => ['LTC_USDT' => ['1h.stchx' => '']],
        ];
        vfsStream::create($structure, $this->vfsRoot);

        // 2. Configure mocks
        $this->binaryStorageMock->method('readHeader')
            ->willReturnCallback(function (string $path): array {
                if (str_contains($path, 'okx/LTC_USDT/1h.stchx')) {
                    throw new StorageException('Read error');
                }
                if (str_contains($path, 'binance/BTC_USDT/4h.stchx')) {
                    return ['numRecords' => 0];
                }
                if (str_contains($path, 'binance/BTC_USDT/1h.stchx')) {
                    return ['numRecords' => 100];
                }
                if (str_contains($path, 'binance/ETH_USDT/1d.stchx')) {
                    return ['numRecords' => 50];
                }

                return ['numRecords' => 0];
            });

        // --- CORRECTED MOCK ---
        $this->binaryStorageMock->method('readRecordByIndex')
            ->willReturnCallback(function (string $path, int $index): array { // Return type is array
                if (str_contains($path, 'binance/BTC_USDT/1h.stchx')) {
                    if ($index === 0) {
                        return ['timestamp' => 1704067200];
                    }
                    if ($index === 99) {
                        return ['timestamp' => 1704423600];
                    }
                }
                if (str_contains($path, 'binance/ETH_USDT/1d.stchx')) {
                    if ($index === 0) {
                        return ['timestamp' => 1706745600];
                    }
                    if ($index === 49) {
                        return ['timestamp' => 1710979200];
                    }
                }

                // Default return of a valid array to prevent TypeErrors
                return ['timestamp' => 0];
            });
        // --- END CORRECTION ---

        $this->loggerMock->expects($this->once())->method('error');

        // 3. Execute
        $manifest = $this->service->getManifest();

        // 4. Assert
        $this->assertCount(2, $manifest, 'Should find 2 symbols with non-empty data');
        $manifestBySymbol = array_column($manifest, null, 'symbol');
        $this->assertArrayHasKey('BTC/USDT', $manifestBySymbol);
        $this->assertArrayHasKey('ETH/USDT', $manifestBySymbol);
    }

    public function testGetManifestHandlesEmptyDirectory(): void
    {
        $manifest = $this->service->getManifest();
        $this->assertEmpty($manifest);
    }
}
