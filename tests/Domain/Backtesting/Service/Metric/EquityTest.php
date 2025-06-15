<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\Equity;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class EquityTest extends TestCase
{
    public static function equityCurveProvider(): array
    {
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

        $longTradeResults = [
            'config' => $config,
            'closedTrades' => [
                [
                    'direction' => DirectionEnum::Long->value, 'quantity' => '2.0',
                    'entryTime' => '2024-01-02 00:00:00', 'exitTime' => '2024-01-04 00:00:00',
                    'entry_commission' => '0.0', 'exit_commission' => '0.0',
                    'entryPrice' => '9990.0', 'pnl' => '40.0',
                ],
            ],
            'marketData' => [
                ['timestamp' => strtotime('2024-01-01 00:00:00'), 'open' => 9900, 'close' => 9900],
                ['timestamp' => strtotime('2024-01-02 00:00:00'), 'open' => 9990, 'close' => 9990],
                ['timestamp' => strtotime('2024-01-03 00:00:00'), 'open' => 9990, 'close' => 9950],
                ['timestamp' => strtotime('2024-01-04 00:00:00'), 'open' => 10010, 'close' => 10010],
                ['timestamp' => strtotime('2024-01-05 00:00:00'), 'open' => 10010, 'close' => 10020],
            ],
        ];
        $expectedLongCurve = [10000.0, 10000.0, 9920.0, 10040.0, 10040.0];

        $shortTradeResults = [
            'config' => $config,
            'closedTrades' => [
                [
                    'direction' => DirectionEnum::Short->value, 'quantity' => '10.0',
                    'entryTime' => '2024-01-02 00:00:00', 'exitTime' => '2024-01-04 00:00:00',
                    'entry_commission' => '0.0', 'exit_commission' => '0.0',
                    'entryPrice' => '500.0', 'pnl' => '100.0',
                ],
            ],
            'marketData' => [
                ['timestamp' => strtotime('2024-01-01 00:00:00'), 'open' => 505, 'close' => 505],
                ['timestamp' => strtotime('2024-01-02 00:00:00'), 'open' => 500, 'close' => 500],
                ['timestamp' => strtotime('2024-01-03 00:00:00'), 'open' => 500, 'close' => 502],
                ['timestamp' => strtotime('2024-01-04 00:00:00'), 'open' => 490, 'close' => 490],
                ['timestamp' => strtotime('2024-01-05 00:00:00'), 'open' => 490, 'close' => 488],
            ],
        ];
        $expectedShortCurve = [10000.0, 10000.0, 9980.0, 10100.0, 10100.0];

        $noTradesResults = [
            'config' => $config,
            'closedTrades' => [],
            'marketData' => [
                ['timestamp' => strtotime('2024-01-01 00:00:00'), 'open' => 100, 'close' => 100],
                ['timestamp' => strtotime('2024-01-02 00:00:00'), 'open' => 100, 'close' => 101],
                ['timestamp' => strtotime('2024-01-03 00:00:00'), 'open' => 101, 'close' => 102],
            ],
        ];
        $expectedNoTradesCurve = [10000.0, 10000.0, 10000.0];

        return [
            'long_trade_scenario' => [$longTradeResults, $expectedLongCurve],
            'short_trade_scenario' => [$shortTradeResults, $expectedShortCurve],
            'no_trades_scenario' => [$noTradesResults, $expectedNoTradesCurve],
        ];
    }

    #[DataProvider('equityCurveProvider')]
    public function testEquityCurveCalculation(array $backtestResults, array $expectedCurve): void
    {
        $metric = new Equity();
        $metric->calculate($backtestResults);
        $values = $metric->getValues();

        $this->assertEquals($expectedCurve, $values);
    }
}
