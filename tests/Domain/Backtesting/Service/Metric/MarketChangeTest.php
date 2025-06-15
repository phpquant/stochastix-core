<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\MarketChange;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class MarketChangeTest extends TestCase
{
    /**
     * Provides various scenarios for MarketChange calculation.
     */
    public static function marketChangeProvider(): array
    {
        return [
            'positive change' => [
                'firstPrice' => 100.0,
                'lastPrice' => 150.0,
                // Change = (150 - 100) / 100 * 100 = 50%
                'expectedChange' => 50.0,
            ],
            'negative change' => [
                'firstPrice' => 200.0,
                'lastPrice' => 150.0,
                // Change = (150 - 200) / 200 * 100 = -25%
                'expectedChange' => -25.0,
            ],
            'no change' => [
                'firstPrice' => 100.0,
                'lastPrice' => 100.0,
                'expectedChange' => 0.0,
            ],
            'edge case: first price is null' => [
                'firstPrice' => null,
                'lastPrice' => 150.0,
                'expectedChange' => null,
            ],
            'edge case: last price is null' => [
                'firstPrice' => 100.0,
                'lastPrice' => null,
                'expectedChange' => null,
            ],
            'edge case: start price is zero' => [
                'firstPrice' => 0.0,
                'lastPrice' => 100.0,
                // Division by zero should result in null
                'expectedChange' => null,
            ],
        ];
    }

    #[DataProvider('marketChangeProvider')]
    public function testMarketChangeCalculation(?float $firstPrice, ?float $lastPrice, ?float $expectedChange): void
    {
        $marketChangeMetric = new MarketChange();

        // Config is needed but its values are not used by this specific metric
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
            'marketFirstPrice' => $firstPrice,
            'marketLastPrice' => $lastPrice,
        ];

        // Calculate the MarketChange metric
        $marketChangeMetric->calculate($backtestResults, $config);
        $metrics = $marketChangeMetric->getMetrics();

        // Assert the result
        $this->assertArrayHasKey('marketChangePercentage', $metrics);
        if ($expectedChange === null) {
            $this->assertNull($metrics['marketChangePercentage']);
        } else {
            $this->assertEqualsWithDelta($expectedChange, $metrics['marketChangePercentage'], 0.01);
        }
    }
}
