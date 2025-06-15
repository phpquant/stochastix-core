<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Stochastix\Domain\Backtesting\Service\ChartDataService;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Stochastix\Domain\Data\Service\IndicatorStorageInterface;
use Stochastix\Domain\Plot\PlotDefinition;
use Stochastix\Domain\Plot\Series\Line;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Stochastix\Domain\Strategy\StrategyInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ChartDataServiceTest extends TestCase
{
    private BacktestResultRepositoryInterface $resultRepositoryMock;
    private BinaryStorageInterface $binaryStorageMock;
    private IndicatorStorageInterface $indicatorStorageMock;
    private StrategyRegistryInterface $strategyRegistryMock;
    private ChartDataService $chartDataService;
    private vfsStreamDirectory $vfsRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resultRepositoryMock = $this->createMock(BacktestResultRepositoryInterface::class);
        $this->binaryStorageMock = $this->createMock(BinaryStorageInterface::class);
        $this->indicatorStorageMock = $this->createMock(IndicatorStorageInterface::class);
        $this->strategyRegistryMock = $this->createMock(StrategyRegistryInterface::class);

        $this->vfsRoot = vfsStream::setup('data');

        $this->chartDataService = new ChartDataService(
            $this->resultRepositoryMock,
            $this->binaryStorageMock,
            $this->indicatorStorageMock,
            $this->strategyRegistryMock,
            vfsStream::url('data/market'),
            vfsStream::url('data/backtests')
        );
    }

    public function testGetChartDataHappyPath(): void
    {
        $runId = 'test_run_123';
        $backtestResults = [
            'config' => [
                'symbols' => ['BTC/USDT'], 'dataSourceExchangeId' => 'binance', 'timeframe' => '1h',
                'strategyAlias' => 'test_strat', 'strategyInputs' => [],
            ],
            'closedTrades' => [
                ['direction' => 'long', 'quantity' => '1', 'entryTime' => '2025-01-01 11:00:00', 'entryPrice' => '100', 'exitTime' => '2025-01-01 12:00:00', 'exitPrice' => '110', 'pnl' => '10'],
                ['direction' => 'short', 'quantity' => '1', 'entryTime' => '2025-01-01 08:00:00', 'entryPrice' => '90', 'exitTime' => '2025-01-01 09:00:00', 'exitPrice' => '80', 'pnl' => '10'],
            ],
        ];
        $ohlcvRecords = [
            ['timestamp' => 1735729200, 'open' => 100.0, 'high' => 102.0, 'low' => 99.0, 'close' => 101.0],
            ['timestamp' => 1735732800, 'open' => 101.0, 'high' => 105.0, 'low' => 100.0, 'close' => 104.0],
        ];
        $indicatorFileData = ['data' => ['ema_fast' => ['value' => [['time' => 1735729200, 'value' => 100.5], ['time' => 1735732800, 'value' => 101.5]]]]];

        vfsStream::create([
            'market' => [
                'binance' => [
                    'BTC_USDT' => ['1h.stchx' => 'dummy_content'],
                ],
            ],
            'backtests' => [
                $runId . '.stchxi' => 'dummy_content',
            ],
        ], $this->vfsRoot);

        // Mocks
        $this->resultRepositoryMock->method('find')->with($runId)->willReturn($backtestResults);
        $this->binaryStorageMock->method('readRecordsByTimestampRange')->willReturn((static fn (): \Generator => yield from $ohlcvRecords)());

        // Configure the indicatorStorage mock correctly
        $this->indicatorStorageMock
            ->method('getFilePath')
            ->willReturn(vfsStream::url('data/backtests/' . $runId . '.stchxi'));
        $this->indicatorStorageMock
            ->method('read')
            ->willReturn($indicatorFileData);

        $mockStrategy = $this->createMock(StrategyInterface::class);
        $mockStrategy->method('getPlotDefinitions')->willReturn(['ema_fast' => new PlotDefinition('Fast EMA', true, [new Line()], [], 'ema_fast')]);
        $this->strategyRegistryMock->method('getStrategy')->willReturn($mockStrategy);

        $chartData = $this->chartDataService->getChartData($runId, null, null, null);

        $this->assertCount(1, $chartData['trades'], 'Only trades within the OHLCV timestamp range should be returned');
        $this->assertSame('long', $chartData['trades'][0]['direction']);
        $this->assertArrayHasKey('ema_fast', $chartData['indicators']);
    }

    public function testGetChartDataThrowsNotFoundForMissingRun(): void
    {
        $runId = 'non_existent_run';
        $this->resultRepositoryMock->method('find')->with($runId)->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Backtest run '{$runId}' not found.");

        $this->chartDataService->getChartData($runId, null, null, null);
    }

    public function testGetChartDataThrowsNotFoundForMissingDataFile(): void
    {
        $runId = 'run_with_missing_data';
        $backtestResults = [
            'config' => [
                'symbols' => ['ETH/USDT'],
                'dataSourceExchangeId' => 'binance',
                'timeframe' => '1h',
                'strategyAlias' => 'test_strat',
            ],
        ];
        $this->resultRepositoryMock->method('find')->with($runId)->willReturn($backtestResults);

        $this->expectException(NotFoundHttpException::class);
        $this->expectExceptionMessage("Data file for symbol 'ETH/USDT' not found.");

        $this->chartDataService->getChartData($runId, null, null, null);
    }
}
