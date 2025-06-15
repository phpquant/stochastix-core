<?php

namespace Stochastix\Domain\Backtesting\Service;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Stochastix\Domain\Data\Service\IndicatorStorageInterface;
use Stochastix\Domain\Data\Service\MetricStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class BacktestResultSaver
{
    public function __construct(
        private BacktestResultRepositoryInterface $resultRepository,
        private IndicatorStorageInterface $indicatorStorage,
        private MetricStorageInterface $metricStorage,
        #[Autowire('%kernel.project_dir%/data/backtests')]
        private string $backtestStoragePath,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Saves all artifacts of a completed backtest run.
     *
     * @param string $runId   the unique ID for the backtest run
     * @param array  $results the complete results array from the Backtester service
     */
    public function save(string $runId, array $results): void
    {
        $this->logger->info('Persisting all backtest artifacts for run ID: {runId}', ['runId' => $runId]);

        $indicatorData = $results['indicatorData'] ?? [];
        $timeSeriesMetrics = $results['timeSeriesMetrics'] ?? [];
        $timestamps = $results['timestamps'] ?? [];

        unset(
            $results['indicatorData'], $results['timeSeriesMetrics'],
            $results['timestamps']
        );
        $this->resultRepository->save($runId, $results);
        $this->logger->info("Summary results saved to {$this->backtestStoragePath}/{$runId}.json");

        if (!empty($indicatorData) && !empty($timestamps)) {
            $indicatorFilePath = $this->indicatorStorage->getFilePath($this->backtestStoragePath, $runId);
            $this->indicatorStorage->write($indicatorFilePath, $timestamps, $indicatorData);
            $this->logger->info("Indicator data saved to {$indicatorFilePath}");
        }

        if (!empty($timeSeriesMetrics) && !empty($timestamps)) {
            $metricFilePath = $this->metricStorage->getFilePath($this->backtestStoragePath, $runId);
            $this->metricStorage->write($metricFilePath, $timestamps, $timeSeriesMetrics);
            $this->logger->info("Time-series metrics saved to {$metricFilePath}");
        }
    }
}
