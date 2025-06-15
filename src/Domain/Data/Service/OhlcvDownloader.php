<?php

namespace Stochastix\Domain\Data\Service;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Exception\DownloaderException;
use Stochastix\Domain\Data\Exception\ExchangeException;
use Stochastix\Domain\Data\Exception\StorageException;
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

    /**
     * Downloads OHLCV data, streams it to a temporary file,
     * and then merges it with any existing data.
     *
     * @return string the final path to the data file
     *
     * @throws DownloaderException
     */
    public function download(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime,
        ?string $jobId = null,
    ): string {
        $finalPath = $this->generateFilePath($exchangeId, $symbol, $timeframe);
        $tempPath = $this->binaryStorage->getTempFilePath($finalPath);
        $mergedPath = $this->binaryStorage->getMergedTempFilePath($finalPath);

        $this->logger->info(
            'Starting download: {exchange}/{symbol} [{timeframe}] from {start} to {end}',
            [
                'exchange' => $exchangeId,
                'symbol' => $symbol,
                'timeframe' => $timeframe,
                'start' => $startTime->format('Y-m-d H:i:s'),
                'end' => $endTime->format('Y-m-d H:i:s'),
                'final_path' => $finalPath,
            ]
        );

        // Ensure temp files are cleaned up if they exist from previous failed runs.
        $this->cleanupFile($tempPath, 'previous .tmp file');
        $this->cleanupFile($mergedPath, 'previous .merged.tmp file');

        try {
            // 1. Download data to the .tmp file
            $this->downloadToTemp($exchangeId, $symbol, $timeframe, $startTime, $endTime, $tempPath, $jobId);

            // Check if any data was actually downloaded before merging/renaming
            if (!file_exists($tempPath) || filesize($tempPath) <= 64) {
                $this->logger->warning('No new data downloaded to {temp}. Ensuring final file exists.', ['temp' => $tempPath]);
                // If original doesn't exist, create an empty one.
                if (!file_exists($finalPath)) {
                    $this->binaryStorage->createFile($finalPath, $symbol, $timeframe);
                }
                $this->cleanupFile($tempPath); // Clean up empty/failed temp.

                return $finalPath; // Return path to original or new empty file.
            }

            // 2. Check if original file exists and needs merging.
            $originalExists = file_exists($finalPath) && filesize($finalPath) > 64;

            if (!$originalExists) {
                // No original file, just rename .tmp to .stchx
                $this->logger->info('No existing data found. Renaming {temp} to {final}.', [
                    'temp' => $tempPath,
                    'final' => $finalPath,
                ]);
                $this->binaryStorage->atomicRename($tempPath, $finalPath);
            } else {
                // Original exists, perform the K-way merge
                $this->logger->info('Existing data found. Merging {final} and {temp} into {merged}.', [
                    'final' => $finalPath,
                    'temp' => $tempPath,
                    'merged' => $mergedPath,
                ]);
                $this->binaryStorage->mergeAndWrite($finalPath, $tempPath, $mergedPath);

                $this->logger->info('Merge complete. Renaming {merged} to {final}.', [
                    'merged' => $mergedPath,
                    'final' => $finalPath,
                ]);
                $this->binaryStorage->atomicRename($mergedPath, $finalPath);
            }

            $this->logger->info('Download and integration successful: {file}', ['file' => $finalPath]);

            // 3. Clean up on success
            $this->cleanupFile($tempPath, '.tmp file after success');
            $this->cleanupFile($mergedPath, '.merged.tmp file after success');

            return $finalPath;
        } catch (\Throwable $e) {
            $this->logger->error(
                'Download failed: {message}. Temp files may remain for inspection: {temp}, {merged}',
                [
                    'message' => $e->getMessage(),
                    'temp' => $tempPath,
                    'merged' => $mergedPath,
                    'exception' => $e,
                ]
            );

            // Re-throw as a DownloaderException
            throw new DownloaderException("Download failed for {$exchangeId}/{$symbol}: {$e->getMessage()}", $e->getCode(), $e);
        }
    }

    /**
     * Handles the actual fetching and writing to the temporary file.
     *
     * @throws DownloaderException|StorageException|ExchangeException
     */
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

        // Create the temp file with its header
        $this->binaryStorage->createFile($tempPath, $symbol, $timeframe);
        $this->logger->debug('Initialized temp file: {file}', ['file' => $tempPath]);

        // Get the generator from the exchange adapter
        $recordsGenerator = $this->exchangeAdapter->fetchOhlcv(
            $exchangeId,
            $symbol,
            $timeframe,
            $startTime,
            $endTime,
            $jobId,
        );
        $this->logger->debug('Starting data fetch to temp file...');

        // Stream records directly to the temp file
        $recordCount = $this->binaryStorage->appendRecords($tempPath, $recordsGenerator);
        $this->logger->info('Streamed {count} records to temp file.', ['count' => $recordCount]);

        // Update the record count in the temp file's header
        if ($recordCount > 0) {
            $this->binaryStorage->updateRecordCount($tempPath, $recordCount);
            $this->logger->debug('Updated temp header record count to {count}.', ['count' => $recordCount]);
        } else {
            $this->logger->warning('No records were downloaded for {symbol} in the specified range.', ['symbol' => $symbol]);
        }
    }

    /**
     * Generates the final path for the .stchx file.
     */
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

    /**
     * Safely deletes a file if it exists.
     */
    private function cleanupFile(string $filePath, string $reason = ''): void
    {
        if (file_exists($filePath)) {
            $this->logger->debug('Cleaning up {reason}: {file}', ['reason' => $reason, 'file' => $filePath]);
            if (!@unlink($filePath)) {
                $this->logger->warning('Could not clean up temporary file: {file}', ['file' => $filePath]);
            }
        }
    }
}
