<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Backtester;
use Stochastix\Domain\Backtesting\Service\MultiTimeframeDataServiceInterface;
use Stochastix\Domain\Backtesting\Service\SeriesMetricServiceInterface;
use Stochastix\Domain\Backtesting\Service\StatisticsServiceInterface;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Enum\TimeframeEnum;
use Stochastix\Domain\Common\Model\MultiTimeframeOhlcvSeries;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;
use Stochastix\Domain\Strategy\AbstractStrategy;
use Stochastix\Domain\Strategy\Attribute\AsStrategy;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Stochastix\Domain\Strategy\StrategyInterface;

class BacktesterTest extends TestCase
{
    private Backtester $backtester;
    private StrategyRegistryInterface $strategyRegistryMock;
    private BinaryStorageInterface $binaryStorageMock;
    private StatisticsServiceInterface $statisticsServiceMock;
    private SeriesMetricServiceInterface $seriesMetricServiceMock;
    private MultiTimeframeDataServiceInterface $multiTimeframeDataServiceMock;
    private vfsStreamDirectory $vfsRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->strategyRegistryMock = $this->createMock(StrategyRegistryInterface::class);
        $this->binaryStorageMock = $this->createMock(BinaryStorageInterface::class);
        $this->statisticsServiceMock = $this->createMock(StatisticsServiceInterface::class);
        $this->seriesMetricServiceMock = $this->createMock(SeriesMetricServiceInterface::class);
        $this->multiTimeframeDataServiceMock = $this->createMock(MultiTimeframeDataServiceInterface::class);

        $this->vfsRoot = vfsStream::setup('data');

        $this->backtester = new Backtester(
            $this->strategyRegistryMock,
            $this->binaryStorageMock,
            $this->statisticsServiceMock,
            $this->seriesMetricServiceMock,
            $this->multiTimeframeDataServiceMock,
            new NullLogger(),
            $this->vfsRoot->url()
        );
    }

    public function testRunExecutesFullLifecycleForSingleSymbol(): void
    {
        $commissionConfig = ['type' => 'percentage', 'rate' => '0.001'];
        $config = new BacktestConfiguration(
            'test_strat',
            'Test\Strategy',
            ['BTC/USDT'],
            TimeframeEnum::D1,
            new \DateTimeImmutable('2023-01-01'),
            new \DateTimeImmutable('2023-01-03'),
            '10000',
            'USDT',
            null,
            $commissionConfig,
            'stchx_binary',
            'vfs',
            [],
            []
        );

        $mockStrategy = $this->createMock(StrategyInterface::class);
        $mockMetadata = new AsStrategy(alias: 'test_strat', name: 'Test Strategy');
        $mockMarketData = [
            ['timestamp' => 1, 'open' => 100, 'high' => 101, 'low' => 99, 'close' => 100, 'volume' => 1000],
            ['timestamp' => 2, 'open' => 100, 'high' => 102, 'low' => 100, 'close' => 101, 'volume' => 1200],
        ];

        $this->strategyRegistryMock->expects($this->once())
            ->method('getStrategy')->with('test_strat')->willReturn($mockStrategy);

        $this->strategyRegistryMock->expects($this->once())
            ->method('getStrategyMetadata')->with('test_strat')->willReturn($mockMetadata);

        vfsStream::create(['vfs' => ['BTC_USDT' => ['1d.stchx' => 'dummy_data']]], $this->vfsRoot);

        $this->binaryStorageMock->expects($this->exactly(2))
            ->method('readRecordsByTimestampRange')
            ->willReturnCallback(static fn (): \Generator => yield from $mockMarketData);

        $mockStrategy->expects($this->once())->method('configure');
        $mockStrategy->expects($this->once())->method('initialize');
        $mockStrategy->expects($this->exactly(2))->method('onBar');

        $this->statisticsServiceMock->expects($this->once())->method('calculate')->willReturn(['summaryMetrics' => ['finalBalance' => '10000']]);
        $this->seriesMetricServiceMock->expects($this->once())->method('calculate')->willReturn(['equity' => ['value' => [10000, 10000]]]);

        $results = $this->backtester->run($config);

        $this->assertIsArray($results);
        $this->assertArrayHasKey('status', $results);
        $this->assertEquals('10000', $results['finalCapital']);
        $this->assertArrayHasKey('statistics', $results);
        $this->assertArrayHasKey('timeSeriesMetrics', $results);
        $this->assertArrayHasKey('equity', $results['timeSeriesMetrics']);
        $this->assertCount(2, $results['timestamps']);
    }

    public function testProgressCallbackIsInvokedCorrectly(): void
    {
        $commissionConfig = ['type' => 'percentage', 'rate' => '0.001'];
        $config = new BacktestConfiguration(
            'test_strat',
            'Test\Strategy',
            ['BTC/USDT'],
            TimeframeEnum::H1,
            null,
            null,
            '10000',
            'USDT',
            null,
            $commissionConfig,
            'stchx_binary',
            'vfs',
            [],
            []
        );

        $mockMarketData = array_fill(0, 5, ['timestamp' => 1, 'open' => 1, 'high' => 1, 'low' => 1, 'close' => 1, 'volume' => 1]);
        $mockMetadata = new AsStrategy(alias: 'test_strat', name: 'Test Strategy');

        $this->strategyRegistryMock->method('getStrategy')->willReturn($this->createMock(StrategyInterface::class));
        $this->strategyRegistryMock->method('getStrategyMetadata')->willReturn($mockMetadata);

        $this->binaryStorageMock->method('readRecordsByTimestampRange')
            ->willReturnCallback(static fn (): \Generator => yield from $mockMarketData);

        vfsStream::create(['vfs' => ['BTC_USDT' => ['1h.stchx' => 'dummy_data']]], $this->vfsRoot);

        $this->statisticsServiceMock->method('calculate')->willReturn([]);
        $this->seriesMetricServiceMock->method('calculate')->willReturn([]);

        $callCount = 0;
        $progressCallback = function (int $processed, int $total) use (&$callCount, $mockMarketData) {
            ++$callCount;
            $this->assertEquals(count($mockMarketData), $total);
            $this->assertEquals($callCount, $processed);
        };

        $this->backtester->run($config, $progressCallback);

        $this->assertEquals(5, $callCount);
    }

    public function testRunHandlesUnclosedShortPositionCorrectly(): void
    {
        $initialCapital = '10000';
        $commissionConfig = ['type' => 'percentage', 'rate' => '0.0']; // No commission for simple test
        $config = new BacktestConfiguration(
            'test_strat',
            'Test\Strategy',
            ['BTC/USDT'],
            TimeframeEnum::D1,
            null,
            null,
            $initialCapital,
            'USDT',
            null,
            $commissionConfig,
            'stchx_binary',
            'vfs',
            [],
            []
        );

        // Mock the AbstractStrategy directly, not the interface, to get access to protected methods.
        $mockStrategy = $this->createMock(AbstractStrategy::class);
        $mockStrategy->method('onBar')
            ->willReturnCallback(function (MultiTimeframeOhlcvSeries $bars) use ($mockStrategy) {
                // We use reflection to call the protected 'entry' method on the mocked object
                $entryCallable = (new \ReflectionMethod(AbstractStrategy::class, 'entry'))->getClosure($mockStrategy);
                $entryCallable(DirectionEnum::Short, OrderTypeEnum::Market, '0.5');
            });

        $this->strategyRegistryMock->method('getStrategy')->willReturn($mockStrategy);
        $this->strategyRegistryMock->method('getStrategyMetadata')->willReturn(new AsStrategy('test_strat', 'Test'));

        $mockMarketData = [
            ['timestamp' => 1, 'open' => 3000, 'high' => 3000, 'low' => 3000, 'close' => 3000, 'volume' => 1000],
            ['timestamp' => 2, 'open' => 3100, 'high' => 3100, 'low' => 3100, 'close' => 3100, 'volume' => 1000],
            ['timestamp' => 3, 'open' => 2900, 'high' => 2900, 'low' => 2900, 'close' => 2900, 'volume' => 1000],
        ];
        vfsStream::create(['vfs' => ['BTC_USDT' => ['1d.stchx' => 'dummy_data']]], $this->vfsRoot);
        $this->binaryStorageMock->method('readRecordsByTimestampRange')->willReturnCallback(static fn (): \Generator => yield from $mockMarketData);

        $this->statisticsServiceMock->method('calculate')->willReturn([]);
        $this->seriesMetricServiceMock->method('calculate')->willReturn([]);

        $results = $this->backtester->run($config);

        // Unrealized PNL = (Entry Price - Current Price) * Quantity = (3100 - 2900) * 0.5 = 100
        $expectedUnrealizedPnl = '100';
        // Final Capital = Initial Capital + Unrealized PNL = 10000 + 100 = 10100
        $expectedFinalCapital = '10100';

        $this->assertCount(1, $results['openPositions']);
        $this->assertEquals($expectedUnrealizedPnl, $results['openPositions'][0]['unrealizedPnl']);
        $this->assertEquals($expectedFinalCapital, $results['finalCapital']);
    }
}
