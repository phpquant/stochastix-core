<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\Cagr;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class CagrTest extends TestCase
{
    /**
     * Provides various scenarios for CAGR calculation.
     */
    public static function cagrCalculationProvider(): array
    {
        return [
            'standard 2-year growth' => [
                'initialCapital' => '10000',
                'finalCapital' => '14400',
                'startDate' => '2022-01-01',
                'endDate' => '2024-01-01', // Exactly 2 years
                'expectedCagr' => 20.0,
            ],
            'short-term growth (6 months)' => [
                'initialCapital' => '10000',
                'finalCapital' => '11000',
                'startDate' => '2023-01-01',
                'endDate' => '2023-07-02', // Exactly 182 days
                'expectedCagr' => 21.06, // (1.1^(1/0.498...)) - 1
            ],
            'no growth' => [
                'initialCapital' => '10000',
                'finalCapital' => '10000',
                'startDate' => '2022-01-01',
                'endDate' => '2024-01-01',
                'expectedCagr' => 0.0,
            ],
            'a loss over one year' => [
                'initialCapital' => '10000',
                'finalCapital' => '8000',
                'startDate' => '2022-01-01',
                'endDate' => '2023-01-01', // Exactly 1 year
                'expectedCagr' => -20.0,
            ],
            'edge case: zero duration' => [
                'initialCapital' => '10000',
                'finalCapital' => '11000',
                'startDate' => '2023-01-01',
                'endDate' => '2023-01-01',
                'expectedCagr' => null, // Cannot divide by zero years
            ],
            'edge case: zero initial capital' => [
                'initialCapital' => '0',
                'finalCapital' => '1000',
                'startDate' => '2022-01-01',
                'endDate' => '2023-01-01',
                'expectedCagr' => null, // Cannot divide by zero capital
            ],
            'edge case: no closed trades, uses config dates' => [
                'initialCapital' => '10000',
                'finalCapital' => '12000',
                'startDate' => '2022-01-01',
                'endDate' => '2023-01-01',
                'expectedCagr' => 20.0,
            ],
        ];
    }

    #[DataProvider('cagrCalculationProvider')]
    public function testCagrCalculation(
        string $initialCapital,
        string $finalCapital,
        string $startDate,
        string $endDate,
        ?float $expectedCagr
    ): void {
        $cagrMetric = new Cagr();

        // 1. Create a mock configuration with the provided dates
        $config = new BacktestConfiguration(
            'test_strat',
            'Test\Strategy',
            ['BTC/USDT'],
            TimeframeEnum::D1,
            new \DateTimeImmutable($startDate),
            new \DateTimeImmutable($endDate),
            $initialCapital,
            'USDT',
            null,
            [],
            'test',
            'test',
            [],
            []
        );

        // 2. Create mock results with the final capital and config
        // The 'closedTrades' array can be empty; the metric should use the config dates.
        $backtestResults = [
            'config' => $config,
            'finalCapital' => $finalCapital,
            'closedTrades' => [],
        ];

        // 3. Calculate the metric
        $cagrMetric->calculate($backtestResults, $config);
        $metrics = $cagrMetric->getMetrics();

        // 4. Assert the result
        $this->assertArrayHasKey('cagrPercentage', $metrics);
        if ($expectedCagr === null) {
            $this->assertNull($metrics['cagrPercentage']);
        } else {
            $this->assertEqualsWithDelta($expectedCagr, $metrics['cagrPercentage'], 0.01);
        }
    }
}
