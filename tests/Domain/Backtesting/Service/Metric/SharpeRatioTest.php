<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\SharpeRatio;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class SharpeRatioTest extends TestCase
{
    private function invokePrivateMethod(object $object, string $methodName, array $parameters)
    {
        $reflection = new \ReflectionClass(get_class($object));

        return $reflection->getMethod($methodName)->invokeArgs($object, $parameters);
    }

    private static function createMockConfig(string $initialCapital = '10000.00'): BacktestConfiguration
    {
        return new BacktestConfiguration(
            'test_strat',
            'Test\\Strategy',
            ['BTC/USDT'],
            TimeframeEnum::D1,
            new \DateTimeImmutable('2023-01-01'),
            new \DateTimeImmutable('2023-12-31'),
            $initialCapital,
            'USDT',
            '1000',
            ['type' => 'percentage', 'rate' => '0.001'],
            'stchx_binary',
            'binance',
            [],
            []
        );
    }

    public static function averageHoldingPeriodDaysProvider(): array
    {
        $trades1 = [['entryTime' => '2023-01-01 10:00:00', 'exitTime' => '2023-01-02 10:00:00', 'pnl' => '10']];

        return [
            'basic trade' => [$trades1, 1.0],
        ];
    }

    #[DataProvider('averageHoldingPeriodDaysProvider')]
    public function testCalculateAverageHoldingPeriodDays(array $closedTrades, float $expectedDays): void
    {
        $metric = new SharpeRatio();
        $this->assertEqualsWithDelta($expectedDays, $this->invokePrivateMethod($metric, 'calculateAverageHoldingPeriodDays', [$closedTrades]), 0.0001);
    }

    public static function calculateSharpeRatioProvider(): array
    {
        $config10k = self::createMockConfig('10000.00');
        $config18250 = self::createMockConfig('18250.00');

        $results_profitable_trades = [
            'closedTrades' => [
                ['pnl' => '100.0', 'entryTime' => '2023-01-01', 'exitTime' => '2023-01-02'],
                ['pnl' => '150.0', 'entryTime' => '2023-01-02', 'exitTime' => '2023-01-03'],
                ['pnl' => '50.0',  'entryTime' => '2023-01-03', 'exitTime' => '2023-01-04'],
            ], 'config' => $config10k, 'finalCapital' => '10300',
        ];
        $results_mixed_trades = [
            'closedTrades' => [
                ['pnl' => '200.0', 'entryTime' => '2023-01-01', 'exitTime' => '2023-01-02'],
                ['pnl' => '-50.0', 'entryTime' => '2023-01-02', 'exitTime' => '2023-01-03'],
                ['pnl' => '150.0', 'entryTime' => '2023-01-03', 'exitTime' => '2023-01-04'],
            ], 'config' => $config10k, 'finalCapital' => '10300',
        ];
        $results_zero_std_dev_positive_mean = [
            'closedTrades' => [['pnl' => '100.0', 'entryTime' => '2023-01-01', 'exitTime' => '2023-01-02'], ['pnl' => '100.0', 'entryTime' => '2023-01-02', 'exitTime' => '2023-01-03']],
            'config' => $config10k, 'finalCapital' => '10200',
        ];
        $results_zero_std_dev_zero_mean_pnl = [
            'closedTrades' => [['pnl' => '0.0', 'entryTime' => '2023-01-01', 'exitTime' => '2023-01-02'], ['pnl' => '0.0', 'entryTime' => '2023-01-02', 'exitTime' => '2023-01-03']],
            'config' => $config10k, 'finalCapital' => '10000',
        ];
        $results_zero_std_dev_zero_numerator_after_rfr = [
            'closedTrades' => [['pnl' => '1.0', 'entryTime' => '2023-01-01', 'exitTime' => '2023-01-02'], ['pnl' => '1.0', 'entryTime' => '2023-01-02', 'exitTime' => '2023-01-03']],
            'config' => $config18250, 'finalCapital' => '18252',
        ];

        return [
            'profitable_rfr_default' => [$results_profitable_trades, $config10k, [], 1.989],
            'profitable_rfr_zero' => [$results_profitable_trades, $config10k, [SharpeRatio::OPTION_ANNUAL_RISK_FREE_RATE => '0.0'], 2.0],
            'mixed_rfr0' => [$results_mixed_trades, $config10k, [SharpeRatio::OPTION_ANNUAL_RISK_FREE_RATE => '0.0'], 0.755],
            'no_trades' => [['closedTrades' => [], 'config' => $config10k], $config10k, [], null],
            'one_trade' => [['closedTrades' => [['pnl' => '100', 'entryTime' => '2023-01-01', 'exitTime' => '2023-01-02']], 'config' => $config10k], $config10k, [], null],
            'zero_std_dev_positive_mean_rfr0' => [$results_zero_std_dev_positive_mean, $config10k, [], 'INF'],
            'zero_std_dev_zero_mean_rfr0' => [$results_zero_std_dev_zero_mean_pnl, $config10k, [], null],
            'zero_std_dev_zero_numerator_after_rfr' => [$results_zero_std_dev_zero_numerator_after_rfr, $config18250, [SharpeRatio::OPTION_ANNUAL_RISK_FREE_RATE => '0.02'], 'INF'],
        ];
    }

    #[DataProvider('calculateSharpeRatioProvider')]
    public function testCalculateSharpeRatio(array $backtestResults, BacktestConfiguration $config, array $options, float|string|null $expectedSharpe): void
    {
        $metric = new SharpeRatio();

        $metric->calculate($backtestResults, $config, $options);
        $sharpeMetrics = $metric->getMetrics();

        $this->assertIsArray($sharpeMetrics);
        $this->assertArrayHasKey('sharpe', $sharpeMetrics);

        if (is_float($expectedSharpe)) {
            $this->assertEqualsWithDelta($expectedSharpe, $sharpeMetrics['sharpe'], 0.001);
        } else {
            $this->assertSame($expectedSharpe, $sharpeMetrics['sharpe']);
        }
    }
}
