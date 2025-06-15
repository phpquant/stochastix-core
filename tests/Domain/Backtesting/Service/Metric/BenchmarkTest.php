<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\Benchmark;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class BenchmarkTest extends TestCase
{
    public function testBenchmarkCalculation(): void
    {
        $metric = new Benchmark();
        $config = new BacktestConfiguration(
            'test',
            'Test',
            [],
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

        $results = [
            'config' => $config,
            'marketFirstPrice' => 100.0,
            'marketData' => [
                ['close' => 100], // Start
                ['close' => 110], // +10%
                ['close' => 99],  // -10% from previous
                ['close' => 120],
            ],
        ];

        $metric->calculate($results);
        $values = $metric->getValues();

        $this->assertCount(4, $values);
        $this->assertEquals(10000.0, $values[0]); // 10000 * (100 / 100)
        $this->assertEquals(11000.0, $values[1]); // 10000 * (110 / 100)
        $this->assertEquals(9900.0, $values[2]);  // 10000 * (99 / 100)
        $this->assertEquals(12000.0, $values[3]); // 10000 * (120 / 100)
    }
}
