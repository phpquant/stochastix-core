<?php

namespace Stochastix\Domain\Data\Service;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Exception\DownloadCancelledException;
use Stochastix\Domain\Data\Exception\DownloaderException;
use Stochastix\Domain\Data\Exception\EmptyHistoryException;
use Stochastix\Domain\Data\Service\Exchange\ExchangeAdapterInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class OhlcvDownloader
{
    public function __construct(
        private ExchangeAdapterInterface $exchangeAdapter,
        private BinaryStorageInterface $binaryStorage,
        #[Autowire('%kernel.project_dir%/data/market')]
        private string $baseDataPath,
        private LoggerInterface $logger,
    ) {
    }

    public function download(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        bool $forceOverwrite = false,
        ?string $jobId = null,
    ): string {
        $finalPath = $this->generateFilePath($exchangeId, $symbol, $timeframe);
        $attemptedTempFiles = [];
        $exception = null;

        try {
            $rangesToDownload = $this->calculateMissingRanges($finalPath, $startTime, $endTime, $timeframe, $forceOverwrite);

            if (empty($rangesToDownload)) {
                $this->logger->info('Local data is already complete for the requested range. No download needed.', ['path' => $finalPath]);
                return $finalPath;
            }

            $this->logger->info('Found {count} missing data range(s) to download.', ['count' => count($rangesToDownload)]);

            foreach ($rangesToDownload as $i => $range) {
                $chunkStartTime = $range[0];
                $chunkEndTime = $range[1];
                $tempPath = $this->binaryStorage->getTempFilePath($finalPath) . ".chunk.{$i}";
                $attemptedTempFiles[] = $tempPath; // Track that we are going to attempt to create this file.
                $this->cleanupFile($tempPath);

                try {
                    $this->logger->info('Downloading chunk #{num}: {start} to {end}', ['num' => $i + 1, 'start' => $chunkStartTime->format('Y-m-d H:i:s'), 'end' => $chunkEndTime->format('Y-m-d H:i:s')]);
                    $this->downloadToTemp($exchangeId, $symbol, $timeframe, $chunkStartTime, $chunkEndTime, $tempPath, $jobId);

                } catch (EmptyHistoryException $e) {
                    $this->logger->warning('Initial date {start_date} is too early. Attempting to find the earliest available data from the exchange.', ['start_date' => $chunkStartTime->format('Y-m-d H:i:s')]);
                    $firstAvailableDate = $this->exchangeAdapter->fetchFirstAvailableTimestamp($exchangeId, $symbol, $timeframe);

                    if ($firstAvailableDate !== null && $firstAvailableDate <= $chunkEndTime) {
                        $this->logger->info('Found earliest data at {real_start}. Resuming download for the adjusted range.', ['real_start' => $firstAvailableDate->format('Y-m-d H:i:s')]);
                        // Retry the download with the adjusted start date
                        $this->downloadToTemp($exchangeId, $symbol, $timeframe, $firstAvailableDate, $chunkEndTime, $tempPath, $jobId);
                    } else {
                        $this->logger->warning('Could not determine a valid start date or the earliest data is outside the requested range for {symbol}. Skipping this chunk.', ['symbol' => $symbol]);
                    }
                }
            }
        } catch (\Throwable $e) {
            $exception = $e; // Store exception to re-throw after finally block
        } finally {
            // Discover any valid temp files that were created, even if the process was interrupted.
            $validTempFiles = [];
            foreach ($attemptedTempFiles as $path) {
                if (file_exists($path) && filesize($path) > 64) {
                    $validTempFiles[] = $path;
                }
            }

            if (!empty($validTempFiles)) {
                $this->logger->info('Merging {count} successfully downloaded chunk(s).', ['count' => count($validTempFiles)]);
                $this->mergeFiles(file_exists($finalPath) ? $finalPath : null, $validTempFiles, $finalPath);
            } elseif ($exception === null && !file_exists($finalPath)) {
                // Only create an empty file if nothing was downloaded AND no error occurred.
                $this->binaryStorage->createFile($finalPath, $symbol, $timeframe);
            }

            // If an exception was caught, re-throw it now after cleanup/merging is done.
            if ($exception instanceof DownloadCancelledException) {
                $this->logger->info('Download was cancelled by user. Progress has been saved.');
                throw $exception;
            } elseif ($exception !== null) {
                $this->logger->error('Download failed: {message}.', ['message' => $exception->getMessage(), 'exception' => $exception]);
                throw new DownloaderException("Download failed for {$exchangeId}/{$symbol}: {$exception->getMessage()}", $exception->getCode(), $exception);
            }
        }

        return $finalPath;
    }

    /**
     * @return array<int, array{\DateTimeImmutable, \DateTimeImmutable}>
     */
    private function calculateMissingRanges(string $filePath, \DateTimeImmutable $requestStart, \DateTimeImmutable $requestEnd, string $timeframe, bool $forceOverwrite): array
    {
        $fileExists = file_exists($filePath) && filesize($filePath) > 64;

        if ($forceOverwrite || !$fileExists) {
            return [[$requestStart, $requestEnd]];
        }

        $header = $this->binaryStorage->readHeader($filePath);

        if ($header['numRecords'] < 1) {
            return [[$requestStart, $requestEnd]];
        }

        $firstRecord = $this->binaryStorage->readRecordByIndex($filePath, 0);
        $lastRecord = $this->binaryStorage->readRecordByIndex($filePath, $header['numRecords'] - 1);

        $localStart = new \DateTimeImmutable()->setTimestamp($firstRecord['timestamp']);
        $localEnd = new \DateTimeImmutable()->setTimestamp($lastRecord['timestamp']);

        $rangesToDownload = [];

        // 1. Calculate the "before" chunk, correctly clipped by the request's end date.
        $beforeChunkStart = $requestStart;
        $beforeChunkEnd = $localStart->modify('-1 second');
        if ($beforeChunkStart < $beforeChunkEnd) {
            $actualEnd = min($requestEnd, $beforeChunkEnd);
            if ($beforeChunkStart <= $actualEnd) {
                $rangesToDownload[] = [$beforeChunkStart, $actualEnd];
            }
        }

        // 2. Calculate internal gaps, correctly clipped by the request's date range.
        $internalGaps = $this->findGapsInFile($filePath, $timeframe);
        foreach ($internalGaps as $gap) {
            $downloadStart = max($requestStart, $gap[0]);
            $downloadEnd = min($requestEnd, $gap[1]);
            if ($downloadStart <= $downloadEnd) {
                $rangesToDownload[] = [$downloadStart, $downloadEnd];
            }
        }

        // 3. Calculate the "after" chunk, correctly clipped by the request's start date.
        $afterChunkStart = $localEnd->modify('+1 second');
        $afterChunkEnd = $requestEnd;
        if ($afterChunkStart < $afterChunkEnd) {
            $actualStart = max($requestStart, $afterChunkStart);
            if ($actualStart <= $afterChunkEnd) {
                $rangesToDownload[] = [$actualStart, $afterChunkEnd];
            }
        }

        return $this->sortAndMergeRanges($rangesToDownload);
    }

    private function findGapsInFile(string $filePath, string $timeframe): array
    {
        $expectedInterval = $this->timeframeToSeconds($timeframe);
        if ($expectedInterval === null) {
            return [];
        }

        $gaps = [];
        $records = $this->binaryStorage->readRecordsSequentially($filePath);
        $previousTimestamp = null;

        foreach ($records as $record) {
            $currentTimestamp = $record['timestamp'];
            if ($previousTimestamp !== null) {
                $diff = $currentTimestamp - $previousTimestamp;
                if ($diff > $expectedInterval) {
                    $gapStart = new \DateTimeImmutable()->setTimestamp($previousTimestamp + $expectedInterval);
                    $gapEnd = new \DateTimeImmutable()->setTimestamp($currentTimestamp - 1);
                    if ($gapStart <= $gapEnd) {
                        $gaps[] = [$gapStart, $gapEnd];
                    }
                }
            }
            $previousTimestamp = $currentTimestamp;
        }

        return $gaps;
    }

    private function mergeFiles(?string $originalPath, array $tempFiles, string $finalPath): void
    {
        $currentFileToMerge = $originalPath;

        foreach ($tempFiles as $i => $tempFile) {
            $mergedPath = $this->binaryStorage->getMergedTempFilePath($finalPath) . ".{$i}";
            if ($currentFileToMerge === null) {
                $this->binaryStorage->atomicRename($tempFile, $mergedPath);
            } else {
                $this->binaryStorage->mergeAndWrite($currentFileToMerge, $tempFile, $mergedPath);
            }

            if ($currentFileToMerge !== null && $currentFileToMerge !== $finalPath) {
                $this->cleanupFile($currentFileToMerge);
            }
            $this->cleanupFile($tempFile);
            $currentFileToMerge = $mergedPath;
        }

        if ($currentFileToMerge !== null) {
            $this->binaryStorage->atomicRename($currentFileToMerge, $finalPath);
        }
    }

    private function downloadToTemp(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        string $tempPath,
        ?string $jobId = null,
    ): void {
        if (!$this->exchangeAdapter->supportsExchange($exchangeId)) {
            throw new DownloaderException("Exchange '{$exchangeId}' is not supported.");
        }

        $this->binaryStorage->createFile($tempPath, $symbol, $timeframe);
        $recordsGenerator = $this->exchangeAdapter->fetchOhlcv($exchangeId, $symbol, $timeframe, $startTime, $endTime, $jobId);

        $recordCount = $this->binaryStorage->streamAndCommitRecords($tempPath, $recordsGenerator);

        if ($recordCount > 0) {
            $this->logger->info('Streamed and committed {count} records to temp file.', ['count' => $recordCount]);
        }
    }

    private function generateFilePath(string $exchangeId, string $symbol, string $timeframe): string
    {
        $sanitizedSymbol = str_replace('/', '_', $symbol);

        return sprintf(
            '%s/%s/%s/%s.stchx',
            rtrim($this->baseDataPath, '/'),
            strtolower($exchangeId),
            strtoupper($sanitizedSymbol),
            $timeframe,
        );
    }

    private function cleanupFile(string $filePath, string $reason = ''): void
    {
        if (file_exists($filePath) && !@unlink($filePath)) {
            $this->logger->warning('Could not clean up temporary file: {file}', ['file' => $filePath]);
        }
    }

    private function timeframeToSeconds(string $timeframe): ?int
    {
        $unit = substr($timeframe, -1);
        $value = (int) substr($timeframe, 0, -1);

        if ($value <= 0) {
            return null;
        }

        return match ($unit) {
            'm' => $value * 60, 'h' => $value * 3600, 'd' => $value * 86400, 'w' => $value * 604800, default => null
        };
    }

    private function sortAndMergeRanges(array $ranges): array
    {
        if (count($ranges) <= 1) {
            return $ranges;
        }

        usort($ranges, static fn ($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        $currentRange = array_shift($ranges);

        foreach ($ranges as $range) {
            if ($range[0] <= $currentRange[1]->modify('+1 second')) {
                $currentRange[1] = max($currentRange[1], $range[1]);
            } else {
                $merged[] = $currentRange;
                $currentRange = $range;
            }
        }

        $merged[] = $currentRange;

        return $merged;
    }
}
