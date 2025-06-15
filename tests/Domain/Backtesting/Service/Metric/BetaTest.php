<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\Benchmark;
use Stochastix\Domain\Backtesting\Service\Metric\Beta;
use Stochastix\Domain\Backtesting\Service\Metric\Equity;
use Stochastix\Domain\Backtesting\Service\Metric\SeriesMetricInterface;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class BetaTest extends TestCase
{
    private static function createConfig(string $initialCapital): BacktestConfiguration
    {
        return new BacktestConfiguration('test', 'Test', [], TimeframeEnum::D1, null, null, $initialCapital, 'USDT', null, [], 'test', 'test', [], []);
    }

    public static function betaCalculationProvider(): array
    {
        $marketData = [
            ['timestamp' => 1, 'open' => 100, 'close' => 100], ['timestamp' => 2, 'open' => 100, 'close' => 110],
            ['timestamp' => 3, 'open' => 110, 'close' => 95],  ['timestamp' => 4, 'open' => 95, 'close' => 120],
            ['timestamp' => 5, 'open' => 120, 'close' => 110],
        ];

        // --- Test Cases ---
        $noTradesResults = ['config' => self::createConfig('100'), 'closedTrades' => [], 'marketFirstPrice' => 100, 'marketData' => $marketData];
        $expectedNoTrades = [null, null, null, 0.0, 0.0];

        $buyAndHoldTrade = [['direction' => DirectionEnum::Long->value, 'quantity' => '1.0', 'entryTime' => date('Y-m-d H:i:s', 1), 'exitTime' => date('Y-m-d H:i:s', 5), 'entry_commission' => '0', 'exit_commission' => '0', 'entryPrice' => '100', 'pnl' => '10']];
        $buyAndHoldResults = ['config' => self::createConfig('100'), 'closedTrades' => $buyAndHoldTrade, 'marketFirstPrice' => 100, 'marketData' => $marketData];
        $expectedBuyAndHold = [null, null, null, 1.0, 1.0];

        $leveragedTrade = [['direction' => DirectionEnum::Long->value, 'quantity' => '2.0', 'entryTime' => date('Y-m-d H:i:s', 1), 'exitTime' => date('Y-m-d H:i:s', 5), 'entry_commission' => '0', 'exit_commission' => '0', 'entryPrice' => '100', 'pnl' => '20']];
        $leveragedResults = ['config' => self::createConfig('100'), 'closedTrades' => $leveragedTrade, 'marketFirstPrice' => 100, 'marketData' => $marketData];
        $expectedLeveraged = [null, null, null, 2.0, 2.0];

        $shortTrade = [['direction' => DirectionEnum::Short->value, 'quantity' => '1.0', 'entryTime' => date('Y-m-d H:i:s', 1), 'exitTime' => date('Y-m-d H:i:s', 5), 'entry_commission' => '0', 'exit_commission' => '0', 'entryPrice' => '100', 'pnl' => '-10']];
        $shortResults = ['config' => self::createConfig('100'), 'closedTrades' => $shortTrade, 'marketFirstPrice' => 100, 'marketData' => $marketData];
        $expectedShort = [null, null, null, -1.0, -1.0];

        return [
            'no_trades_beta_is_zero' => [$noTradesResults, 3, $expectedNoTrades],
            'buy_and_hold_beta_is_one' => [$buyAndHoldResults, 3, $expectedBuyAndHold],
            'leveraged_long_beta_is_two' => [$leveragedResults, 3, $expectedLeveraged],
            'short_sell_beta_is_minus_one' => [$shortResults, 3, $expectedShort],
        ];
    }

    #[DataProvider('betaCalculationProvider')]
    public function testBetaCalculation(array $backtestResults, int $window, array $expectedValues): void
    {
        $betaMetric = new Beta($window);

        $equityMetric = new Equity();
        $benchmarkMetric = new Benchmark();

        $betaMetric->setDependencyResults([
            Equity::class => ['values' => $this->runMetric($equityMetric, $backtestResults)],
            Benchmark::class => ['values' => $this->runMetric($benchmarkMetric, $backtestResults)],
        ]);
        $betaMetric->calculate($backtestResults);
        $values = $betaMetric->getValues();

        $this->assertCount(count($expectedValues), $values);
        foreach ($expectedValues as $i => $expected) {
            if ($expected === null) {
                $this->assertNull($values[$i], "Failed asserting that value at index {$i} is null.");
            } else {
                $this->assertEqualsWithDelta($expected, $values[$i], 0.001, "Failed asserting beta value at index {$i}.");
            }
        }
    }

    private function runMetric(SeriesMetricInterface $metric, array $results): array
    {
        $metric->calculate($results);

        return $metric->getValues();
    }
}
