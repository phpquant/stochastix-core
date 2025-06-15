<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\SortinoRatio;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class SortinoRatioTest extends TestCase
{
    /**
     * Provides various scenarios for Sortino Ratio calculation.
     */
    public static function sortinoRatioProvider(): array
    {
        return [
            'standard case with wins and losses' => [
                'closedTrades' => [
                    ['pnl' => '100'],
                    ['pnl' => '150'],
                    ['pnl' => '-80'],
                    ['pnl' => '-20'],
                ],
                // Mean PnL = 37.5; Losing PnLs = [-80, -20]; Downside Deviation (stdev of losses) = 30
                // Sortino = 37.5 / 30 = 1.25
                'expectedSortino' => 1.25,
            ],
            'only winning trades' => [
                'closedTrades' => [
                    ['pnl' => '100'],
                    ['pnl' => '50'],
                ],
                // Downside deviation is 0, mean PnL is positive. Result should be infinity.
                'expectedSortino' => 'INF',
            ],
            'only losing trades' => [
                'closedTrades' => [
                    ['pnl' => '-50'],
                    ['pnl' => '-100'],
                ],
                // Mean PnL = -75; Downside Deviation = 25
                // Sortino = -75 / 25 = -3.0
                'expectedSortino' => -3.0,
            ],
            'no trades' => [
                'closedTrades' => [],
                // Not enough data to calculate.
                'expectedSortino' => null,
            ],
            'one trade' => [
                'closedTrades' => [['pnl' => '100']],
                // Not enough data to calculate.
                'expectedSortino' => null,
            ],
            'no losing trades (break-even)' => [
                'closedTrades' => [
                    ['pnl' => '100'],
                    ['pnl' => '0'],
                ],
                // Downside deviation is 0, mean PnL is positive.
                'expectedSortino' => 'INF',
            ],
            'mean pnl is zero, no losses' => [
                'closedTrades' => [
                    ['pnl' => '0'],
                    ['pnl' => '0'],
                ],
                // Numerator is 0, denominator is 0. Metric is not meaningful.
                'expectedSortino' => null,
            ],
        ];
    }

    #[DataProvider('sortinoRatioProvider')]
    public function testSortinoRatioCalculation(array $closedTrades, float|string|null $expectedSortino): void
    {
        $sortinoMetric = new SortinoRatio();

        $config = new BacktestConfiguration(
            'test_strat',
            'Test\Strategy',
            ['BTC/USDT'],
            TimeframeEnum::D1,
            null,
            null,
            '10000',
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
            'closedTrades' => $closedTrades,
        ];

        // Calculate the Sortino Ratio
        $sortinoMetric->calculate($backtestResults, $config);
        $metrics = $sortinoMetric->getMetrics();

        // Assert the result
        $this->assertArrayHasKey('sortino', $metrics);

        if (is_float($expectedSortino)) {
            $this->assertEqualsWithDelta($expectedSortino, $metrics['sortino'], 0.001);
        } else {
            // Handles null and 'INF' string cases
            $this->assertSame($expectedSortino, $metrics['sortino']);
        }
    }
}
