<?php

namespace Stochastix\Domain\Data\Service\Exchange;

use ccxt\Exchange;
use Psr\Cache\CacheItemPoolInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Event\DownloadProgressEvent;
use Stochastix\Domain\Data\Exception\DownloadCancelledException;
use Stochastix\Domain\Data\Exception\EmptyHistoryException;
use Stochastix\Domain\Data\Exception\ExchangeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class CcxtAdapter implements ExchangeAdapterInterface
{
    public function __construct(
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger,
        private ExchangeFactory $exchangeFactory,
        #[Autowire(service: 'stochastix.download.cancel.cache')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function supportsExchange(string $exchangeId): bool
    {
        return \in_array($exchangeId, Exchange::$exchanges, true);
    }

    public function fetchOhlcv(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?string $jobId = null,
    ): \Generator {
        $exchange = $this->exchangeFactory->create($exchangeId);
        $exchange->loadMarkets();

        if (!$exchange->has['fetchOHLCV']) {
            throw new ExchangeException(sprintf('Exchange "%s" does not support fetching OHLCV data.', $exchangeId));
        }

        $timeframes = $exchange->timeframes ?? [];
        if (!\array_key_exists($timeframe, $timeframes)) {
            throw new ExchangeException(sprintf('Exchange "%s" does not support the "%s" timeframe. Supported: %s', $exchangeId, $timeframe, implode(', ', array_keys($timeframes))));
        }

        $since = $startTime->getTimestamp() * 1000;
        $endTimestamp = $endTime->getTimestamp() * 1000;
        $limit = $exchange->limits['OHLCV']['limit'] ?? 1000;
        $durationMs = Exchange::parse_timeframe($timeframe) * 1000;
        $totalDuration = max(1, $endTimestamp - $since);
        $isFirstFetch = true;
        $cancellationCacheKey = 'download.cancel.' . $jobId;

        while ($since <= $endTimestamp) {
            if ($jobId) {
                $cancellationItem = $this->cache->getItem($cancellationCacheKey);
                if ($cancellationItem->isHit()) {
                    $this->cache->deleteItem($cancellationCacheKey);
                    throw new DownloadCancelledException("Download job {$jobId} was cancelled by user request.");
                }
            }

            try {
                $ohlcvs = $exchange->fetch_ohlcv($symbol, $timeframe, $since, $limit);
            } catch (\Throwable $e) {
                throw new ExchangeException(sprintf('Failed to fetch OHLCV for %s on %s: %s', $symbol, $exchangeId, $e->getMessage()), 0, $e);
            }

            if (empty($ohlcvs)) {
                if ($isFirstFetch) {
                    throw new EmptyHistoryException("Exchange returned no data for {$symbol} starting from {$startTime->format('Y-m-d H:i:s')}. Data may not be available for this period.");
                }
                $this->logger->info("No more OHLCV data returned for {$symbol} starting from " . ($since / 1000));
                break;
            }

            $isFirstFetch = false;

            $lastTimestamp = 0;
            $batchRecordCount = 0;

            foreach ($ohlcvs as $ohlcv) {
                [$timestamp, $open, $high, $low, $close, $volume] = $ohlcv;

                if ($timestamp > $endTimestamp) {
                    break 2;
                }
                if ($timestamp < $since) {
                    continue;
                }

                yield [
                    'timestamp' => (int) ($timestamp / 1000),
                    'open' => (float) $open,
                    'high' => (float) $high,
                    'low' => (float) $low,
                    'close' => (float) $close,
                    'volume' => (float) $volume,
                ];

                $lastTimestamp = $timestamp;
                ++$batchRecordCount;
            }

            if ($lastTimestamp > 0) {
                $event = new DownloadProgressEvent(
                    $jobId,
                    $symbol,
                    (int) ($lastTimestamp / 1000),
                    $batchRecordCount,
                    $totalDuration,
                    max(0, $lastTimestamp - $startTime->getTimestamp() * 1000),
                );
                $this->eventDispatcher->dispatch($event);
            }

            if ($lastTimestamp === 0) {
                $this->logger->info("No valid records found in the fetched batch for {$symbol}. Stopping.");
                break;
            }

            $since = $lastTimestamp + $durationMs;
            usleep(200000);
        }
    }

    public function fetchFirstAvailableTimestamp(string $exchangeId, string $symbol, string $timeframe): ?\DateTimeImmutable
    {
        $exchange = $this->exchangeFactory->create($exchangeId);
        if (!$exchange->has['fetchOHLCV']) {
            return null; // The exchange can't fetch candles at all.
        }

        try {
            // Attempt to fetch just the very first record available from the exchange.
            $ohlcvs = $exchange->fetch_ohlcv($symbol, $timeframe, null, 1);

            // If a record is returned, extract its timestamp.
            if (!empty($ohlcvs) && isset($ohlcvs[0][0])) {
                // CCXT timestamps are in milliseconds, convert to seconds.
                return (new \DateTimeImmutable())->setTimestamp((int) ($ohlcvs[0][0] / 1000));
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not determine first available timestamp for {symbol} on {exchange}.',
                ['symbol' => $symbol, 'exchange' => $exchangeId, 'reason' => $e->getMessage()]
            );

            return null;
        }

        return null;
    }
}
