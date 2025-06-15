<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\Drawdown;
use Stochastix\Domain\Backtesting\Service\Metric\Equity;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class DrawdownTest extends TestCase
{
    public static function drawdownCalculationProvider(): array
    {
        $baseConfigData = ['initialCapital' => '10000'];

        return [
            'standard_drawdown_scenario' => [
                'configData' => $baseConfigData,
                'equityCurve' => [10000.0, 10000.0, 9920.0, 10040.0],
                'expectedDrawdown' => [0.0, 0.0, -0.8, 0.0],
            ],
            'no_trades_scenario' => [
                'configData' => $baseConfigData,
                'equityCurve' => [10000.0, 10000.0, 10000.0, 10000.0],
                'expectedDrawdown' => [0.0, 0.0, 0.0, 0.0],
            ],
            'all_wins_scenario' => [
                'configData' => $baseConfigData,
                'equityCurve' => [10000.0, 10100.0, 10250.0, 10300.0],
                'expectedDrawdown' => [0.0, 0.0, 0.0, 0.0],
            ],
            'all_losses_scenario' => [
                'configData' => $baseConfigData,
                'equityCurve' => [10000.0, 9500.0, 9200.0, 8800.0],
                'expectedDrawdown' => [0.0, -5.0, -8.0, -12.0],
            ],
            'recovery_scenario' => [
                'configData' => $baseConfigData,
                'equityCurve' => [10000.0, 9500.0, 9800.0, 10200.0, 10100.0],
                'expectedDrawdown' => [0.0, -5.0, -2.0, 0.0, -0.9804],
            ],
            'no_equity_data_scenario' => [
                'configData' => $baseConfigData,
                'equityCurve' => [],
                'expectedDrawdown' => [],
            ],
        ];
    }

    #[DataProvider('drawdownCalculationProvider')]
    public function testDrawdownCalculation(array $configData, array $equityCurve, array $expectedDrawdown): void
    {
        $drawdownMetric = new Drawdown();

        $config = new BacktestConfiguration(
            'test',
            'Test',
            [],
            TimeframeEnum::D1,
            null,
            null,
            $configData['initialCapital'],
            'USDT',
            null,
            [],
            'test',
            'test',
            [],
            []
        );

        // 1. Mock the dependency result from the Equity metric
        $dependencyResults = [
            Equity::class => ['values' => $equityCurve],
        ];

        // 2. Set the dependencies on the metric being tested
        $drawdownMetric->setDependencyResults($dependencyResults);

        // 3. Now, calculate the Drawdown. The 'calculate' method requires a 'config' key in its argument array.
        $drawdownMetric->calculate(['config' => $config]);
        $values = $drawdownMetric->getValues();

        // 4. Assert the results. Use assertEqualsWithDelta for floating-point comparisons.
        self::assertEqualsWithDelta($expectedDrawdown, $values, 0.001);
    }
}
