<?php

namespace Stochastix\Domain\Backtesting\Service;

use Ds\Map;
use Psr\Log\NullLogger;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Stochastix\Domain\Data\Service\IndicatorStorageInterface;
use Stochastix\Domain\Indicator\Model\IndicatorManager;
use Stochastix\Domain\Order\Model\OrderManager;
use Stochastix\Domain\Order\Model\PortfolioManager;
use Stochastix\Domain\Order\Model\Pricing\PercentageCommission;
use Stochastix\Domain\Order\Service\OrderExecutor;
use Stochastix\Domain\Plot\PlotComponentInterface;
use Stochastix\Domain\Strategy\Model\StrategyContext;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ChartDataService
{
    public function __construct(
        private BacktestResultRepositoryInterface $resultRepository,
        private BinaryStorageInterface $binaryStorage,
        private IndicatorStorageInterface $indicatorStorage,
        private StrategyRegistryInterface $strategyRegistry,
        #[Autowire('%kernel.project_dir%/data/market')]
        private string $baseDataPath,
        #[Autowire('%kernel.project_dir%/data/backtests')]
        private string $backtestStoragePath,
    ) {
    }

    public function getChartData(string $runId, ?int $from, ?int $to, ?int $countback): array
    {
        $results = $this->resultRepository->find($runId);
        if ($results === null) {
            throw new NotFoundHttpException("Backtest run '{$runId}' not found.");
        }

        $config = $results['config'];
        $symbol = $config['symbols'][0] ?? null;
        if ($symbol === null) {
            throw new \InvalidArgumentException("No symbol found in backtest configuration for run '{$runId}'.");
        }

        $filePath = $this->generateFilePath($config['dataSourceExchangeId'], $symbol, $config['timeframe']);
        if (!file_exists($filePath)) {
            throw new NotFoundHttpException("Data file for symbol '{$symbol}' not found.");
        }

        $readFrom = $from ?? 0;
        $readTo = $to ?? PHP_INT_MAX;
        $ohlcvRecords = iterator_to_array($this->binaryStorage->readRecordsByTimestampRange($filePath, $readFrom, $readTo));
        if ($countback !== null && $from === null) {
            $ohlcvRecords = array_slice($ohlcvRecords, -$countback);
        }
        if (empty($ohlcvRecords)) {
            return ['ohlcv' => [], 'trades' => [], 'openPositions' => [], 'indicators' => []];
        }

        $finalFrom = $ohlcvRecords[0]['timestamp'];
        $finalTo = end($ohlcvRecords)['timestamp'];
        $ohlcvData = array_map(static fn ($r) => ['time' => $r['timestamp'], 'open' => $r['open'], 'high' => $r['high'], 'low' => $r['low'], 'close' => $r['close']], $ohlcvRecords);

        // Process Closed Trades
        $closedTrades = $results['closedTrades'] ?? [];
        $processedClosedTrades = [];
        foreach ($closedTrades as $trade) {
            $entryTimestamp = (new \DateTimeImmutable($trade['entryTime']))->getTimestamp();
            $exitTimestamp = (new \DateTimeImmutable($trade['exitTime']))->getTimestamp();
            if (($entryTimestamp >= $finalFrom && $entryTimestamp <= $finalTo) || ($exitTimestamp >= $finalFrom && $exitTimestamp <= $finalTo)) {
                $processedClosedTrades[] = [
                    'direction' => $trade['direction'], 'quantity' => $trade['quantity'], 'entryTime' => $entryTimestamp,
                    'entryPrice' => bcadd($trade['entryPrice'], '0'), 'exitTime' => $exitTimestamp,
                    'exitPrice' => bcadd($trade['exitPrice'], '0'), 'pnl' => bcadd($trade['pnl'], '0'),
                ];
            }
        }

        // Process Open Positions
        $openPositions = $results['openPositions'] ?? [];
        $processedOpenPositions = [];
        foreach ($openPositions as $position) {
            $entryTimestamp = (new \DateTimeImmutable($position['entryTime']))->getTimestamp();
            if ($entryTimestamp >= $finalFrom && $entryTimestamp <= $finalTo) {
                $processedOpenPositions[] = [
                    'direction' => $position['direction'],
                    'quantity' => $position['quantity'],
                    'entryTime' => $entryTimestamp,
                    'entryPrice' => bcadd($position['entryPrice'], '0'),
                    'currentPrice' => bcadd($position['currentPrice'], '0'),
                    'unrealizedPnl' => bcadd($position['unrealizedPnl'], '0'),
                ];
            }
        }

        // Process Indicators
        $indicatorData = [];
        $strategy = $this->strategyRegistry->getStrategy($config['strategyAlias']);
        if ($strategy) {
            $strategy->configure($config['strategyInputs']);
            $cursor = new BacktestCursor();
            $dataframes = new Map();

            $strategy->initialize(new StrategyContext(
                new IndicatorManager($cursor, $dataframes),
                new OrderManager(
                    new OrderExecutor(new PercentageCommission(0)),
                    new PortfolioManager(new NullLogger()),
                    $cursor,
                    new NullLogger()
                ),
                $cursor,
                $dataframes
            ));
            $plotDefinitions = $strategy->getPlotDefinitions();

            $indicatorFilePath = $this->indicatorStorage->getFilePath($this->backtestStoragePath, $runId);
            if (file_exists($indicatorFilePath)) {
                $allIndicatorFileData = $this->indicatorStorage->read($indicatorFilePath);
                $allIndicatorData = $allIndicatorFileData['data'];

                foreach ($plotDefinitions as $indicatorKey => $plotDefinition) {
                    $metadata = [
                        'name' => $plotDefinition->name,
                        'overlay' => $plotDefinition->overlay,
                        'plots' => array_map(static fn (PlotComponentInterface $p) => $p->toArray(), $plotDefinition->plots),
                        'annotations' => array_map(static fn (PlotComponentInterface $p) => $p->toArray(), $plotDefinition->annotations),
                    ];

                    $dataForIndicator = [];
                    if (isset($allIndicatorData[$indicatorKey])) {
                        foreach ($allIndicatorData[$indicatorKey] as $seriesKey => $seriesData) {
                            $dataForIndicator[$seriesKey] = array_values(array_filter(
                                $seriesData,
                                static fn ($point) => $point['time'] >= $finalFrom && $point['time'] <= $finalTo
                            ));
                        }
                    }

                    $indicatorData[$indicatorKey] = [
                        'metadata' => $metadata,
                        'data' => $dataForIndicator,
                    ];
                }
            }
        }

        return [
            'ohlcv' => $ohlcvData,
            'trades' => $processedClosedTrades,
            'openPositions' => $processedOpenPositions,
            'indicators' => $indicatorData,
        ];
    }

    private function generateFilePath(string $exchangeId, string $symbol, string $timeframe): string
    {
        $sanitizedSymbol = str_replace('/', '_', $symbol);

        return sprintf(
            '%s/%s/%s/%s.stchx',
            rtrim($this->baseDataPath, '/'),
            strtolower($exchangeId),
            strtoupper($sanitizedSymbol),
            strtolower($timeframe)
        );
    }
}
