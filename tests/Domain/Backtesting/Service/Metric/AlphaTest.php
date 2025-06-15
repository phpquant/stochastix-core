<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Service\Metric\Alpha;
use Stochastix\Domain\Backtesting\Service\Metric\Benchmark;
use Stochastix\Domain\Backtesting\Service\Metric\Beta;
use Stochastix\Domain\Backtesting\Service\Metric\Equity;

class AlphaTest extends TestCase
{
    public static function alphaDataProvider(): array
    {
        // Test case 1: Positive Alpha
        // Portfolio returns: +12%, +8%, -2%
        // Benchmark returns: +10%, +6%, -3%
        $equityValues1 = [100, 112, 120.96, 118.54];
        $benchmarkValues1 = [100, 110, 116.6, 113.102];
        $betaValues1 = [null, null, 1.1, 0.9];
        // Alpha[2] = 0.08 - (1.1 * 0.06) = 0.014
        // Alpha[3] = -0.02 - (0.9 * -0.03) = 0.007
        $expectedAlpha1 = [null, null, null, 0.014, 0.007]; // Padded to align with a 5-point market data series

        // Test case 2: Negative Alpha
        // Portfolio returns: +8%, +5%, +1%
        // Benchmark returns: +10%, +6%, +2%
        $equityValues2 = [100, 108, 113.4, 114.534];
        $benchmarkValues2 = [100, 110, 116.6, 118.932];
        $betaValues2 = [null, null, 1.0, 1.2];
        // Alpha[2] = 0.05 - (1.0 * 0.06) = -0.01
        // Alpha[3] = 0.01 - (1.2 * 0.02) = -0.014
        $expectedAlpha2 = [null, null, null, -0.01, -0.014];

        return [
            'positive_alpha_scenario' => [$equityValues1, $benchmarkValues1, $betaValues1, 5, $expectedAlpha1],
            'negative_alpha_scenario' => [$equityValues2, $benchmarkValues2, $betaValues2, 5, $expectedAlpha2],
        ];
    }

    #[DataProvider('alphaDataProvider')]
    public function testAlphaCalculation(array $equity, array $benchmark, array $beta, int $marketDataPoints, array $expectedAlpha): void
    {
        $alphaMetric = new Alpha();

        // Mock the results that would be provided by the service orchestrator
        $dependencyResults = [
            Equity::class => ['values' => $equity],
            Benchmark::class => ['values' => $benchmark],
            Beta::class => ['values' => $beta],
        ];

        // The calculate method only needs marketData for the timestamps
        $mockBacktestResults = [
            'marketData' => array_fill(0, $marketDataPoints, ['timestamp' => 0, 'close' => 0]),
        ];

        $alphaMetric->setDependencyResults($dependencyResults);
        $alphaMetric->calculate($mockBacktestResults);

        $actualValues = $alphaMetric->getValues();

        $this->assertCount(count($expectedAlpha), $actualValues);

        foreach ($expectedAlpha as $i => $expected) {
            if ($expected === null) {
                $this->assertNull($actualValues[$i]);
            } else {
                $this->assertEqualsWithDelta($expected, $actualValues[$i], 0.0001, "Failed at index {$i}");
            }
        }
    }
}
