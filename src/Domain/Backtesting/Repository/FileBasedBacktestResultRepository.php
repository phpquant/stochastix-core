<?php

namespace Stochastix\Domain\Backtesting\Repository;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;

final readonly class FileBasedBacktestResultRepository implements BacktestResultRepositoryInterface
{
    private Filesystem $filesystem;

    public function __construct(
        #[Autowire('%kernel.project_dir%/data/backtests')]
        private string $storagePath,
        private LoggerInterface $logger
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Creates a unique, descriptive ID for a new backtest run.
     * This ID will be used as the base for the filename.
     */
    public function generateRunId(string $strategyAlias): string
    {
        $timestamp = date('Ymd-His');
        $uniquePart = substr(uniqid('', true), -6); // Add a short unique part to avoid collisions

        return sprintf('%s_%s_%s', $timestamp, $strategyAlias, $uniquePart);
    }

    public function save(string $runId, array $results): void
    {
        $this->filesystem->mkdir($this->storagePath);
        $filePath = $this->getFilePath($runId, '.json');
        $json = json_encode($results, JSON_PRETTY_PRINT);

        $this->filesystem->dumpFile($filePath, $json);
        $this->logger->info('Backtest results saved to: {file}', ['file' => $filePath]);
    }

    public function find(string $runId): ?array
    {
        $filePath = $this->getFilePath($runId, '.json');

        if (!$this->filesystem->exists($filePath)) {
            return null;
        }

        $json = file_get_contents($filePath);

        return json_decode($json, true);
    }

    public function delete(string $runId): bool
    {
        $jsonPath = $this->getFilePath($runId, '.json');
        $indicatorPath = $this->getFilePath($runId, '.stchxi');
        $metricPath = $this->getFilePath($runId, '.stchxm');
        $pathsToDelete = array_filter(
            [$jsonPath, $indicatorPath, $metricPath],
            fn ($path) => $this->filesystem->exists($path)
        );

        if (empty($pathsToDelete)) {
            $this->logger->warning('No files found to delete for run ID: {runId}', ['runId' => $runId]);

            return false;
        }

        try {
            $this->filesystem->remove($pathsToDelete);
            $this->logger->info(
                'Successfully deleted files for run ID: {runId}',
                ['runId' => $runId, 'files' => $pathsToDelete]
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error(
                'Failed to delete files for run ID {runId}: {message}',
                ['runId' => $runId, 'message' => $e->getMessage()]
            );
            // Re-throw or handle as a critical error if needed
            throw $e;
        }
    }

    /**
     * Finds all backtest runs, parses metadata from filenames, and returns them sorted by date descending.
     *
     * @return array<int, array{runId: string, timestamp: int, strategyAlias: string}>
     */
    public function findAllMetadata(): array
    {
        if (!$this->filesystem->exists($this->storagePath)) {
            return [];
        }

        $finder = new Finder();
        $finder->in($this->storagePath)->files()->name('*.json')->sortByName();

        $results = [];
        foreach ($finder as $file) {
            $runId = $file->getBasename('.json');
            // Filename format: YYYYMMDD-HHMMSS_strategy_alias_with_underscores_unique-part
            $parts = explode('_', $runId);

            if (count($parts) >= 3) {
                $timestampStr = array_shift($parts);
                array_pop($parts); // Remove the unique part from the end
                $strategyAlias = implode('_', $parts); // Re-join the middle parts

                $dateTime = \DateTime::createFromFormat('Ymd-His', $timestampStr);
                if ($dateTime) {
                    $results[] = [
                        'runId' => $runId,
                        'timestamp' => $dateTime->getTimestamp(),
                        'strategyAlias' => $strategyAlias,
                    ];
                }
            }
        }

        // Sort by timestamp descending (newest first)
        usort($results, static fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return $results;
    }

    private function getFilePath(string $runId, string $extension): string
    {
        return $this->storagePath . '/' . $runId . $extension;
    }
}
