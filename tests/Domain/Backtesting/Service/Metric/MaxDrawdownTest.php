<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\MaxDrawdown;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class MaxDrawdownTest extends TestCase
{
    private function createConfig(string $initialCapital): BacktestConfiguration
    {
        return new BacktestConfiguration(
            'test',
            'Test',
            [],
            TimeframeEnum::D1,
            null,
            null,
            $initialCapital,
            'USDT',
            null,
            [],
            'test',
            'test',
            [],
            []
        );
    }

    public function testMaxDrawdownCalculation(): void
    {
        $metric = new MaxDrawdown();
        $config = $this->createConfig('1000');
        $results = [
            'config' => $config,
            'closedTrades' => [
                ['pnl' => '200'],
                ['pnl' => '-100'],
                ['pnl' => '-150'],
                ['pnl' => '300'],
                ['pnl' => '-50'],
            ],
        ];

        // 1. Calculate the metric (stores result internally)
        $metric->calculate($results, $config);
        // 2. Get the calculated metrics
        $drawdownMetrics = $metric->getMetrics();

        // 3. Assert the results
        $this->assertIsArray($drawdownMetrics);
        $this->assertEqualsWithDelta(250.0, $drawdownMetrics['absoluteDrawdown'], 0.001);
        $this->assertEqualsWithDelta(20.83, $drawdownMetrics['maxAccountUnderwaterPercentage'], 0.01);
    }

    public function testNoTradesReturnsZeroDrawdown(): void
    {
        $metric = new MaxDrawdown();
        $config = $this->createConfig('1000');
        $results = ['config' => $config, 'closedTrades' => []];

        $metric->calculate($results, $config);
        $drawdownMetrics = $metric->getMetrics();

        $this->assertEquals(0.0, $drawdownMetrics['absoluteDrawdown']);
        $this->assertEquals(0.0, $drawdownMetrics['maxAccountUnderwaterPercentage']);
    }
}
