<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\AbstractSummaryMetric;

// --- Test stubs for the DataProvider ---

/**
 * A stub implementation of AbstractSummaryMetric for testing purposes.
 */
class SimpleSummaryMetric extends AbstractSummaryMetric
{
    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
    }
}

/**
 * A stub with a multi-word name to test camelCase conversion.
 */
class TwoWordSummaryMetric extends AbstractSummaryMetric
{
    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
    }
}

/**
 * A stub with an acronym to test camelCase conversion edge cases.
 */
class AWPSummaryMetric extends AbstractSummaryMetric
{
    public function calculate(array $backtestResults, BacktestConfiguration $config, array $options = []): void
    {
    }
}

/**
 * Unit test for the AbstractSummaryMetric class.
 */
class AbstractSummaryMetricTest extends TestCase
{
    // --- DataProvider for testing the getName() method ---

    public static function nameProvider(): array
    {
        return [
            'simple name' => ['simpleSummaryMetric', new SimpleSummaryMetric()],
            'two word name' => ['twoWordSummaryMetric', new TwoWordSummaryMetric()],
            'acronym name' => ['AWPSummaryMetric', new AWPSummaryMetric()],
        ];
    }

    #[DataProvider('nameProvider')]
    public function testGetName(string $expectedName, AbstractSummaryMetric $metric): void
    {
        $this->assertSame($expectedName, $metric->getName());
    }

    // --- Tests for the getMetrics() method ---

    public function testGetMetricsThrowsExceptionWhenNotCalculated(): void
    {
        $metric = new SimpleSummaryMetric();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Metric "Stochastix\Tests\Domain\Backtesting\Service\Metric\SimpleSummaryMetric" has not been calculated yet. Call calculate() first.');

        $metric->getMetrics();
    }

    public static function metricsProvider(): array
    {
        return [
            'single metric' => [['sharpe' => 1.89]],
            'multiple metrics' => [['cagr' => 25.5, 'maxDrawdown' => -10.2]],
            'empty metrics array' => [[]],
            'metric with null value' => [['calmar' => null]],
        ];
    }

    #[DataProvider('metricsProvider')]
    public function testGetMetricsReturnsCorrectData(array $testMetrics): void
    {
        $metric = new SimpleSummaryMetric();

        // Use reflection to set the protected $calculatedMetrics property
        $reflection = new \ReflectionClass(AbstractSummaryMetric::class);
        $metricsProperty = $reflection->getProperty('calculatedMetrics');
        $metricsProperty->setValue($metric, $testMetrics);

        $this->assertSame($testMetrics, $metric->getMetrics());
    }
}
