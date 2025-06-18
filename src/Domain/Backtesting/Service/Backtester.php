<?php

namespace Stochastix\Domain\Backtesting\Service;

use Ds\Map;
use Ds\Vector;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Event\BacktestPhaseEvent;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Enum\OhlcvEnum;
use Stochastix\Domain\Common\Model\MultiTimeframeOhlcvSeries;
use Stochastix\Domain\Data\Exception\DataFileNotFoundException;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Stochastix\Domain\Indicator\Model\IndicatorManager;
use Stochastix\Domain\Order\Dto\OrderSignal;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;
use Stochastix\Domain\Order\Model\OrderManager;
use Stochastix\Domain\Order\Model\PortfolioManager;
use Stochastix\Domain\Order\Model\Pricing\FixedCommission;
use Stochastix\Domain\Order\Model\Pricing\FixedPerUnitCommission;
use Stochastix\Domain\Order\Model\Pricing\PercentageCommission;
use Stochastix\Domain\Order\Service\OrderExecutor;
use Stochastix\Domain\Strategy\Model\StrategyContext;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class Backtester
{
    public function __construct(
        private StrategyRegistryInterface $strategyRegistry,
        private BinaryStorageInterface $binaryStorage,
        private StatisticsServiceInterface $statisticsService,
        private SeriesMetricServiceInterface $seriesMetricService,
        private MultiTimeframeDataServiceInterface $multiTimeframeDataService,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/data/market')]
        private string $baseDataPath,
    ) {
    }

    public function run(BacktestConfiguration $config, string $runId, ?callable $progressCallback = null): array
    {
        $this->eventDispatcher->dispatch(new BacktestPhaseEvent($runId, 'initialization', 'start'));
        $this->logger->info('Starting backtest run for strategy: {strategy}', ['strategy' => $config->strategyAlias]);

        $portfolioManager = new PortfolioManager($this->logger);
        $portfolioManager->initialize($config->initialCapital, $config->stakeCurrency);
        $commissionConfig = $config->commissionConfig;
        $commissionModel = match ($commissionConfig['type']) {
            'percentage' => new PercentageCommission($commissionConfig['rate']),
            'fixed_per_trade' => new FixedCommission($commissionConfig['amount']),
            'fixed_per_unit' => new FixedPerUnitCommission($commissionConfig['rate']),
            default => throw new \InvalidArgumentException('Unsupported commission type.'),
        };
        $orderExecutor = new OrderExecutor($commissionModel);

        $cursor = new BacktestCursor();
        $orderManager = new OrderManager($orderExecutor, $portfolioManager, $cursor, $this->logger);

        $totalBarsProcessedOverall = 0;
        $totalBarsToProcessOverall = 0;
        $marketFirstPrice = null;
        $marketLastPrice = null;

        foreach ($config->symbols as $symbol) {
            $filePath = $this->generateFilePath($config->dataSourceExchangeId, $symbol, $config->timeframe->value);
            if (!file_exists($filePath)) {
                continue;
            }

            $startTimestamp = $config->startDate?->getTimestamp() ?? 0;
            $endTimestamp = $config->endDate?->getTimestamp() ?? PHP_INT_MAX;

            $allRecordsForSymbol = iterator_to_array($this->binaryStorage->readRecordsByTimestampRange($filePath, $startTimestamp, $endTimestamp));
            if (empty($allRecordsForSymbol)) {
                continue;
            }

            $totalBarsToProcessOverall += count($allRecordsForSymbol);

            if ($marketFirstPrice === null) {
                $marketFirstPrice = $allRecordsForSymbol[0]['close'];
            }
            $marketLastPrice = end($allRecordsForSymbol)['close'];
        }

        $indicatorDataForSave = [];
        $allTimestamps = [];
        $lastBars = null;
        $this->eventDispatcher->dispatch(new BacktestPhaseEvent($runId, 'initialization', 'stop'));

        $this->eventDispatcher->dispatch(new BacktestPhaseEvent($runId, 'loop', 'start'));
        foreach ($config->symbols as $symbol) {
            $this->logger->info('--- Starting backtest for Symbol: {symbol} ---', ['symbol' => $symbol]);
            $strategy = $this->strategyRegistry->getStrategy($config->strategyAlias);
            $strategyMetadata = $this->strategyRegistry->getStrategyMetadata($config->strategyAlias);
            $requiredMarketData = $strategyMetadata?->requiredMarketData ?? [];

            $filePath = $this->generateFilePath($config->dataSourceExchangeId, $symbol, $config->timeframe->value);

            if (!file_exists($filePath)) {
                throw new DataFileNotFoundException("Data file not found for primary timeframe '{$config->timeframe->value}' and symbol '{$symbol}': {$filePath}");
            }

            $this->logger->info('Loading primary data from: {file}', ['file' => $filePath]);
            $startTimestamp = $config->startDate?->getTimestamp() ?? 0;
            $endTimestamp = $config->endDate?->getTimestamp() ?? PHP_INT_MAX;
            $allRecords = iterator_to_array($this->binaryStorage->readRecordsByTimestampRange($filePath, $startTimestamp, $endTimestamp));
            if (empty($allRecords)) {
                $this->logger->warning('No data found for {symbol} in the given range.', ['symbol' => $symbol]);
                continue;
            }

            $primaryTimestamps = new Vector(array_column($allRecords, 'timestamp'));
            if (empty($allTimestamps)) {
                $allTimestamps = $primaryTimestamps->toArray();
            }

            $dataframes = new Map();

            foreach ($requiredMarketData as $timeframeEnum) {
                $this->logger->info("Loading and resampling secondary timeframe data for {$timeframeEnum->value}...");
                $dataframes->put(
                    $timeframeEnum->value,
                    $this->multiTimeframeDataService->loadAndResample($symbol, $config->dataSourceExchangeId, $primaryTimestamps, $timeframeEnum)
                );
            }
            $this->logger->info('All secondary data loaded.');

            $marketData = [
                OhlcvEnum::Timestamp->value => $primaryTimestamps, OhlcvEnum::Open->value => new Vector(array_column($allRecords, 'open')),
                OhlcvEnum::High->value => new Vector(array_column($allRecords, 'high')), OhlcvEnum::Low->value => new Vector(array_column($allRecords, 'low')),
                OhlcvEnum::Close->value => new Vector(array_column($allRecords, 'close')), OhlcvEnum::Volume->value => new Vector(array_column($allRecords, 'volume')),
            ];
            $dataframes->put('primary', $marketData);

            $indicatorManager = new IndicatorManager($cursor, $dataframes);
            $strategyContext = new StrategyContext($indicatorManager, $orderManager, $cursor, $dataframes);

            $strategyContext->currentSymbol = $symbol;
            $strategy?->configure($config->strategyInputs);
            $strategy?->initialize($strategyContext);
            $this->logger->info('Strategy initialized for {symbol}.', ['symbol' => $symbol]);

            $this->logger->info('Pre-calculating indicators across all timeframes...');
            $indicatorManager->calculateBatch();
            $this->logger->info('Indicators calculated.');
            $symbolIndicatorData = $indicatorManager->getAllOutputDataForSave();
            $indicatorDataForSave = array_merge_recursive($indicatorDataForSave, $symbolIndicatorData);

            $primaryData = $dataframes->get('primary');
            $secondaryDataframes = $dataframes->copy();
            $secondaryDataframes->remove('primary');
            $bars = new MultiTimeframeOhlcvSeries($primaryData, $secondaryDataframes, $cursor);
            $lastBars = $bars;

            $barCount = count($allRecords);

            $this->logger->info('Starting backtest loop for {count} bars...', ['count' => $barCount]);

            for ($i = 0; $i < $barCount; ++$i) {
                $cursor->currentIndex = $i;
                $executionTime = (new \DateTimeImmutable())->setTimestamp($bars->timestamp[0]);
                $orderManager->checkPendingOrders($bars, $i);
                $orderManager->processSignalQueue($bars, $executionTime);
                foreach ($portfolioManager->getAllOpenPositions() as $position) {
                    if ($position->symbol !== $symbol) {
                        continue;
                    }
                    $exitPrice = null;
                    $exitReason = null;
                    if ($position->direction === DirectionEnum::Long) {
                        if ($position->initialStopLossPrice !== null && bccomp((string) $bars->low[0], $position->initialStopLossPrice) <= 0) {
                            $exitPrice = $position->initialStopLossPrice;
                            $exitReason = 'stop_loss';
                        } elseif ($position->initialTakeProfitPrice !== null && bccomp((string) $bars->high[0], $position->initialTakeProfitPrice) >= 0) {
                            $exitPrice = $position->initialTakeProfitPrice;
                            $exitReason = 'take_profit';
                        }
                    } else {
                        if ($position->initialStopLossPrice !== null && bccomp((string) $bars->high[0], $position->initialStopLossPrice) >= 0) {
                            $exitPrice = $position->initialStopLossPrice;
                            $exitReason = 'stop_loss';
                        } elseif ($position->initialTakeProfitPrice !== null && bccomp((string) $bars->low[0], $position->initialTakeProfitPrice) <= 0) {
                            $exitPrice = $position->initialTakeProfitPrice;
                            $exitReason = 'take_profit';
                        }
                    }
                    if ($exitPrice !== null) {
                        $exitSignal = new OrderSignal($position->symbol, $position->direction === DirectionEnum::Long ? DirectionEnum::Short : DirectionEnum::Long, OrderTypeEnum::Market, $position->quantity, $exitPrice, null, null, null, null, null, [$exitReason]);
                        $orderManager->queueExit($position->symbol, $exitSignal);
                    }
                }
                $orderManager->processSignalQueue($bars, $executionTime);
                if (bccomp($orderManager->getPortfolioManager()->getAvailableCash(), '0.00000001') < 0) {
                    $this->logger->critical('Capital depleted. Stopping backtest for symbol {symbol} at bar {index}.', ['symbol' => $symbol, 'index' => $i]);
                    break;
                }
                $strategy?->onBar($bars);
                ++$totalBarsProcessedOverall;
                if ($progressCallback) {
                    $progressCallback($totalBarsProcessedOverall, $totalBarsToProcessOverall);
                }
            }

            $this->logger->info('--- Finished backtest for Symbol: {symbol} ---', ['symbol' => $symbol]);
        }
        $this->eventDispatcher->dispatch(new BacktestPhaseEvent($runId, 'loop', 'stop'));

        $this->eventDispatcher->dispatch(new BacktestPhaseEvent($runId, 'statistics', 'start'));
        $this->logger->info('All symbols processed.');

        // 1. Sum P&L from all closed trades
        $closedTrades = $portfolioManager->getClosedTrades();
        $totalClosedPnl = '0';

        foreach ($closedTrades as $trade) {
            $totalClosedPnl = bcadd($totalClosedPnl, $trade['pnl']);
        }

        // 2. Process open positions and sum their unrealized P&L
        $processedOpenPositions = [];
        $totalUnrealizedPnl = '0';

        if ($lastBars !== null) {
            $openPositions = $portfolioManager->getAllOpenPositions();
            $lastClosePrice = $lastBars->close[0] ?? null;

            if ($lastClosePrice !== null) {
                foreach ($openPositions as $position) {
                    $entryPrice = $position->entryPrice;
                    $quantity = $position->quantity;

                    if ($position->direction === DirectionEnum::Long) {
                        $pnl = bcmul(bcsub((string) $lastClosePrice, $entryPrice), $quantity);
                    } else {
                        $pnl = bcmul(bcsub($entryPrice, (string) $lastClosePrice), $quantity);
                    }
                    $totalUnrealizedPnl = bcadd($totalUnrealizedPnl, $pnl);

                    $processedOpenPositions[] = [
                        'symbol' => $position->symbol,
                        'direction' => $position->direction->value,
                        'quantity' => $position->quantity,
                        'entryPrice' => $position->entryPrice,
                        'entryTime' => $position->entryTime->format('Y-m-d H:i:s'),
                        'currentPrice' => (string) $lastClosePrice,
                        'unrealizedPnl' => bcadd($pnl, '0'),
                    ];
                }
            }
        }

        // 3. Calculate the true final capital
        $finalCapital = bcadd($config->initialCapital, $totalClosedPnl);
        $finalCapital = bcadd($finalCapital, $totalUnrealizedPnl);

        $results = [
            'status' => "Backtest completed. Processed {$totalBarsProcessedOverall} bars across " . count($config->symbols) . ' symbols.',
            'config' => $config,
            'finalCapital' => bcadd($finalCapital, '0'),
            'closedTrades' => $closedTrades,
            'openPositions' => $processedOpenPositions,
            'indicatorData' => $indicatorDataForSave,
            'marketFirstPrice' => $marketFirstPrice,
            'marketLastPrice' => $marketLastPrice,
            'marketData' => $allRecords ?? [],
            'timestamps' => $allTimestamps,
        ];

        $this->logger->info('Calculating final summary statistics...');
        $results['statistics'] = $this->statisticsService->calculate($results);
        $this->logger->info('Summary statistics calculated.');
        $this->logger->info('Calculating time-series metrics...');
        $results['timeSeriesMetrics'] = $this->seriesMetricService->calculate($results);
        $this->logger->info('Time-series metrics calculated.');
        unset($results['marketData']);
        $this->eventDispatcher->dispatch(new BacktestPhaseEvent($runId, 'statistics', 'stop'));

        return $results;
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
