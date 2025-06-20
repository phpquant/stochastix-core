<?php

namespace Stochastix\Domain\Chart\Service;

use Ds\Map;
use Ds\Vector;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Chart\Dto\ChartIndicatorRequestDto;
use Stochastix\Domain\Chart\Dto\IndicatorRequest;
use Stochastix\Domain\Common\Enum\AppliedPriceEnum;
use Stochastix\Domain\Common\Enum\OhlcvEnum;
use Stochastix\Domain\Common\Enum\TALibFunctionEnum;
use Stochastix\Domain\Common\Model\Series;
use Stochastix\Domain\Data\Exception\DataFileNotFoundException;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Stochastix\Domain\Indicator\Model\TALibIndicator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Orchestrates the on-demand loading of market data and calculation of
 * indicators for interactive charting.
 */
final readonly class ChartIndicatorService
{
    public function __construct(
        private BinaryStorageInterface $binaryStorage,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/data/market')]
        private string $baseDataPath,
    ) {
    }

    public function getIndicatorData(ChartIndicatorRequestDto $dto): array
    {
        // 1. Calculate the required lookback period to ensure indicators are fully "warmed up".
        $lookback = $this->getMaxLookback($dto->indicators);
        $this->logger->info('Required lookback period calculated', ['lookback' => $lookback]);

        // 2. Load the necessary slice of OHLCV data, including the lookback period.
        $ohlcvRecords = $this->loadOhlcvData($dto, $lookback);
        if (empty($ohlcvRecords)) {
            return ['ohlcv' => [], 'indicators' => []];
        }
        $this->logger->info('Loaded {count} OHLCV records (including lookback).', ['count' => count($ohlcvRecords)]);

        // 3. Prepare dataframes from the loaded records for the indicator calculation engine.
        $dataframes = new Map(['primary' => $this->createDataframeFromRecords($ohlcvRecords)]);
        $this->logger->info('Dataframes prepared for calculation.');

        // 4. Instantiate and calculate all requested indicators.
        $calculatedIndicators = $this->calculateIndicators($dto->indicators, $dataframes);
        $this->logger->info('{count} indicators calculated.', ['count' => count($calculatedIndicators)]);

        // 5. Trim the lookback data from the series and format the final response for the API.
        return $this->formatResponse($ohlcvRecords, $calculatedIndicators, $lookback);
    }

    private function generateFilePath(string $exchangeId, string $symbol, string $timeframe): string
    {
        $sanitizedSymbol = str_replace('/', '_', $symbol);
        return sprintf(
            '%s/%s/%s/%s.stchx',
            rtrim($this->baseDataPath, '/'),
            strtolower($exchangeId),
            strtoupper($sanitizedSymbol),
            $timeframe
        );
    }

    /**
     * @param array<IndicatorRequest> $indicators
     */
    private function getMaxLookback(array $indicators): int
    {
        if (empty($indicators)) {
            return 0;
        }

        $maxLookback = 0;
        foreach ($indicators as $req) {
            // A more sophisticated mapping could be used here for complex indicators.
            // For now, we assume the longest 'timePeriod' or 'slowPeriod' is a good proxy.
            $period = $req->params['slowPeriod'] ?? $req->params['timePeriod'] ?? 0;
            if ($period > $maxLookback) {
                $maxLookback = $period;
            }
        }
        // Add a buffer, as some indicators (like MACD) require more than just the longest period.
        return $maxLookback + 34;
    }

    private function loadOhlcvData(ChartIndicatorRequestDto $dto, int $lookback): array
    {
        $filePath = $this->generateFilePath($dto->exchangeId, $dto->symbol, $dto->timeframe);
        if (!file_exists($filePath)) {
            throw new DataFileNotFoundException("Data file not found: {$filePath}");
        }

        $header = $this->binaryStorage->readHeader($filePath);
        $recordCount = $header['numRecords'];

        // This logic is a simplified pagination for demonstration. A production system
        // might use a more robust cursor-based pagination.
        $endTimestamp = $dto->toTimestamp ?? $this->binaryStorage->readRecordByIndex($filePath, $recordCount - 1)['timestamp'];
        $startTimestamp = $dto->fromTimestamp ?? 0;
        $limit = $dto->countback ?? 1000;

        // Fetch the visible window plus the lookback period.
        $recordsToFetch = $limit + $lookback;

        // Note: For true 'countback', we'd need to find the index of 'endTimestamp'
        // and read backwards. `readRecordsByTimestampRange` is a forward-only search.
        // This implementation fetches a window ending at `endTimestamp`.
        $allRecordsInRange = iterator_to_array(
            $this->binaryStorage->readRecordsByTimestampRange($filePath, $startTimestamp, $endTimestamp)
        );

        // We take the tail of the result to simulate `countback`.
        return array_slice($allRecordsInRange, -$recordsToFetch);
    }

    private function createDataframeFromRecords(array $records): array
    {
        $dataframe = [];
        foreach (OhlcvEnum::cases() as $case) {
            $dataframe[$case->value] = new Vector(array_column($records, $case->value));
        }
        return $dataframe;
    }

    /**
     * @param array<IndicatorRequest> $indicatorRequests
     * @return array<string, array<string, Series>>
     */
    private function calculateIndicators(array $indicatorRequests, Map $dataframes): array
    {
        $results = [];
        foreach ($indicatorRequests as $request) {
            // For now, we only handle TA-Lib indicators. This can be extended.
            if ($request->type !== 'talib') {
                continue;
            }

            try {
                $functionEnum = TALibFunctionEnum::from('trader_' . strtolower($request->function));
                $sourceEnum = AppliedPriceEnum::from($request->source);

                $indicator = new TALibIndicator($functionEnum, $request->params, $sourceEnum);
                $indicator->calculateBatch($dataframes);

                $results[$request->key] = $indicator->getAllSeries();
            } catch (\Throwable $e) {
                $this->logger->error('Failed to calculate indicator {key}: {message}', [
                    'key' => $request->key,
                    'message' => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }
        return $results;
    }

    /**
     * @param array<array<string, mixed>> $ohlcvRecords
     * @param array<string, array<string, Series>> $calculatedIndicators
     */
    private function formatResponse(array $ohlcvRecords, array $calculatedIndicators, int $lookback): array
    {
        // Trim the lookback period from the OHLCV data.
        $visibleOhlcv = array_slice($ohlcvRecords, $lookback);
        $formattedOhlcv = array_map(
            static fn ($r) => ['time' => $r['timestamp'], 'open' => $r['open'], 'high' => $r['high'], 'low' => $r['low'], 'close' => $r['close']],
            $visibleOhlcv
        );

        $formattedIndicators = [];
        foreach ($calculatedIndicators as $indicatorKey => $seriesMap) {
            $formattedIndicators[$indicatorKey] = [];
            foreach ($seriesMap as $seriesKey => $series) {
                // Also trim the lookback period from each indicator series.
                $visibleValues = array_slice($series->toArray(), $lookback);
                $timestamps = array_column($formattedOhlcv, 'time');

                $dataPoints = [];
                foreach ($timestamps as $index => $ts) {
                    if (isset($visibleValues[$index]) && $visibleValues[$index] !== null) {
                        $dataPoints[] = ['time' => $ts, 'value' => $visibleValues[$index]];
                    }
                }
                $formattedIndicators[$indicatorKey][$seriesKey] = $dataPoints;
            }
        }

        return [
            'ohlcv' => $formattedOhlcv,
            'indicators' => $formattedIndicators,
        ];
    }
}
