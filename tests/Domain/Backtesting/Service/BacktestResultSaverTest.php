<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Stochastix\Domain\Backtesting\Service\BacktestResultSaver;
use Stochastix\Domain\Data\Service\IndicatorStorageInterface;
use Stochastix\Domain\Data\Service\MetricStorageInterface;

class BacktestResultSaverTest extends TestCase
{
    private BacktestResultRepositoryInterface $resultRepositoryMock;
    private IndicatorStorageInterface $indicatorStorageMock;
    private MetricStorageInterface $metricStorageMock;
    private BacktestResultSaver $saver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resultRepositoryMock = $this->createMock(BacktestResultRepositoryInterface::class);
        $this->indicatorStorageMock = $this->createMock(IndicatorStorageInterface::class);
        $this->metricStorageMock = $this->createMock(MetricStorageInterface::class);

        $this->saver = new BacktestResultSaver(
            $this->resultRepositoryMock,
            $this->indicatorStorageMock,
            $this->metricStorageMock,
            '/var/tmp/backtests',
            new NullLogger()
        );
    }

    public function testSaveWithAllData(): void
    {
        $runId = 'test_run_all_data';
        $results = [
            'config' => [],
            'indicatorData' => ['ema' => []],
            'timeSeriesMetrics' => ['equity' => []],
            'timestamps' => [1, 2, 3],
        ];

        // Expect all save/write methods to be called
        $this->resultRepositoryMock->expects($this->once())->method('save');
        $this->indicatorStorageMock->expects($this->once())->method('write');
        $this->metricStorageMock->expects($this->once())->method('write');

        $this->saver->save($runId, $results);
    }

    public function testSaveWithOnlySummaryResults(): void
    {
        $runId = 'test_run_summary_only';
        $results = [
            'config' => [],
            // Missing indicatorData, timeSeriesMetrics, and timestamps
        ];

        // Expect only the main repository to be called
        $this->resultRepositoryMock->expects($this->once())->method('save');
        $this->indicatorStorageMock->expects($this->never())->method('write');
        $this->metricStorageMock->expects($this->never())->method('write');

        $this->saver->save($runId, $results);
    }

    public function testSaveSkipsTimeSeriesIfTimestampsAreMissing(): void
    {
        $runId = 'test_run_no_timestamps';
        $results = [
            'config' => [],
            'indicatorData' => ['ema' => []],
            'timeSeriesMetrics' => ['equity' => []],
            'timestamps' => [], // Timestamps are empty
        ];

        // Expect only the main repository to be called
        $this->resultRepositoryMock->expects($this->once())->method('save');
        $this->indicatorStorageMock->expects($this->never())->method('write');
        $this->metricStorageMock->expects($this->never())->method('write');

        $this->saver->save($runId, $results);
    }
}
