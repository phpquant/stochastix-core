<?php

namespace Stochastix\Domain\Data\Service\Exchange;

use ccxt\Exchange;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Event\DownloadProgressEvent;
use Stochastix\Domain\Data\Exception\ExchangeException;

class CcxtAdapter implements ExchangeAdapterInterface
{
    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        private readonly LoggerInterface $logger,
        private readonly ExchangeFactory $exchangeFactory
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

        while ($since <= $endTimestamp) {
            try {
                $ohlcvs = $exchange->fetch_ohlcv($symbol, $timeframe, $since, $limit);
            } catch (\Throwable $e) {
                throw new ExchangeException(sprintf('Failed to fetch OHLCV for %s on %s: %s', $symbol, $exchangeId, $e->getMessage()), 0, $e);
            }

            if (empty($ohlcvs)) {
                $this->logger->info("No more OHLCV data returned for {$symbol} starting from " . ($since / 1000));
                break;
            }

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
}
