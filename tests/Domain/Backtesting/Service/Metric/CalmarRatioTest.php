<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\Cagr;
use Stochastix\Domain\Backtesting\Service\Metric\CalmarRatio;
use Stochastix\Domain\Backtesting\Service\Metric\MaxDrawdown;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class CalmarRatioTest extends TestCase
{
    /**
     * Provides various scenarios for Calmar Ratio calculation.
     */
    public static function calmarRatioProvider(): array
    {
        // Scenario 1: Standard positive case
        $trades1 = [
            ['pnl' => '2500'],  // Peak 12500
            ['pnl' => '-2500'], // Drawdown to 10000 (DD of 2500 from peak 12500 -> 20%)
            ['pnl' => '2000'],  // Final 12000
        ];

        // Scenario 2: Negative CAGR
        $trades2 = [
            ['pnl' => '1000'],
            ['pnl' => '-4000'], // Peak 11000, DD to 7000 (DD of 4000 -> 36.36%)
        ];

        // Scenario 3: Zero drawdown (all wins)
        $trades3 = [
            ['pnl' => '1000'],
            ['pnl' => '1000'],
            ['pnl' => '1000'],
        ];

        return [
            'standard positive case' => [
                'initialCapital' => '10000',
                'finalCapital' => '12000',
                'startDate' => '2022-01-01',
                'endDate' => '2023-01-01', // 1 year
                'closedTrades' => $trades1,
                // CAGR = 20%, MaxDrawdown = 20% => Calmar = 20/20 = 1.0
                'expectedCalmar' => 1.0,
            ],
            'negative cagr case' => [
                'initialCapital' => '10000',
                'finalCapital' => '7000', // -30%
                'startDate' => '2022-01-01',
                'endDate' => '2023-01-01',
                'closedTrades' => $trades2,
                // CAGR = -30%, MaxDrawdown = 36.36% => Calmar = -30 / 36.36 = -0.825
                'expectedCalmar' => -0.825,
            ],
            'zero drawdown case' => [
                'initialCapital' => '10000',
                'finalCapital' => '13000',
                'startDate' => '2022-01-01',
                'endDate' => '2023-01-01',
                'closedTrades' => $trades3,
                // Division by zero drawdown results in null
                'expectedCalmar' => null,
            ],
            'zero cagr case' => [
                'initialCapital' => '10000',
                'finalCapital' => '10000',
                'startDate' => '2022-01-01',
                'endDate' => '2023-01-01',
                'closedTrades' => [['pnl' => '-1000'], ['pnl' => '1000']],
                // Calmar should be 0 if CAGR is 0
                'expectedCalmar' => 0.0,
            ],
            'cagr is null (zero duration)' => [
                'initialCapital' => '10000',
                'finalCapital' => '11000',
                'startDate' => '2022-01-01',
                'endDate' => '2022-01-01',
                'closedTrades' => [['pnl' => '1000']],
                // Calmar should be null if CAGR is null
                'expectedCalmar' => null,
            ],
        ];
    }

    #[DataProvider('calmarRatioProvider')]
    public function testCalmarRatioCalculation(
        string $initialCapital,
        string $finalCapital,
        string $startDate,
        string $endDate,
        array $closedTrades,
        ?float $expectedCalmar
    ): void {
        // 1. Instantiate the CalmarRatio and its dependencies
        $cagrMetric = new Cagr();
        $maxDrawdownMetric = new MaxDrawdown();
        $calmarRatioMetric = new CalmarRatio($cagrMetric, $maxDrawdownMetric);

        // 2. Create mock configuration and results
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

        $backtestResults = [
            'config' => $config,
            'finalCapital' => $finalCapital,
            'closedTrades' => $closedTrades,
        ];

        // 3. Calculate the Calmar Ratio
        $calmarRatioMetric->calculate($backtestResults, $config);
        $metrics = $calmarRatioMetric->getMetrics();

        // 4. Assert the result
        $this->assertArrayHasKey('calmar', $metrics);

        if ($expectedCalmar === null) {
            $this->assertNull($metrics['calmar']);
        } else {
            $this->assertEqualsWithDelta($expectedCalmar, $metrics['calmar'], 0.001);
        }
    }
}
