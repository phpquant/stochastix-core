<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\MaxDrawdown;
use Stochastix\Domain\Backtesting\Service\Metric\SharpeRatio;
use Stochastix\Domain\Backtesting\Service\StatisticsService;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

class StatisticsServiceTest extends TestCase
{
    private StatisticsService $statisticsService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->statisticsService = new StatisticsService([
            'sharpeRatio' => new SharpeRatio(),
            'maxDrawdown' => new MaxDrawdown(),
        ]);
    }

    private function getMockResults(): array
    {
        $config = new BacktestConfiguration(
            'test_strat',
            'Test',
            ['BTC/USDT'],
            TimeframeEnum::D1,
            new \DateTimeImmutable('2024-01-01'),
            new \DateTimeImmutable('2024-01-05'),
            '1000',
            'USDT',
            null,
            [],
            'test',
            'test',
            [],
            []
        );

        $closedTrades = [
            [
                'symbol' => 'BTC/USDT', 'pnl' => '100',
                'entryTime' => '2024-01-01 10:00:00', 'exitTime' => '2024-01-01 11:00:00',
                'quantity' => '1', 'entryPrice' => '1000',
                'enter_tags' => ['ema_cross'], 'exit_tags' => ['exit_signal'],
            ],
            [
                'symbol' => 'BTC/USDT', 'pnl' => '-50',
                'entryTime' => '2024-01-02 10:00:00', 'exitTime' => '2024-01-02 10:30:00',
                'quantity' => '1', 'entryPrice' => '1100',
                'enter_tags' => ['ema_cross', 'rsi_confirm'], 'exit_tags' => ['stop_loss'],
            ],
            [
                'symbol' => 'BTC/USDT', 'pnl' => '200',
                'entryTime' => '2024-01-03 10:00:00', 'exitTime' => '2024-01-03 12:00:00',
                'quantity' => '1', 'entryPrice' => '1050',
                'enter_tags' => ['rsi_confirm'], 'exit_tags' => ['take_profit'],
            ],
        ];

        return ['config' => $config, 'closedTrades' => $closedTrades, 'finalCapital' => '1250'];
    }

    public function testCalculatePairStats(): void
    {
        $stats = $this->statisticsService->calculate($this->getMockResults());
        $pairStats = $stats['pairStats'][0];

        $this->assertCount(1, $stats['pairStats']);
        $this->assertEquals('BTC/USDT', $pairStats['label']);
        $this->assertEquals(3, $pairStats['trades']);
        $this->assertEquals(2, $pairStats['wins']);
        $this->assertEquals(1, $pairStats['losses']);
        $this->assertEquals(250.0, $pairStats['totalProfit']);
        $this->assertEqualsWithDelta(7.94, $pairStats['totalProfitPercentage'], 0.01);
        $this->assertEquals(70, $pairStats['avgDurationMin']);
    }

    public function testCalculateEnterTagStats(): void
    {
        $stats = $this->statisticsService->calculate($this->getMockResults());
        $enterTagStats = $stats['enterTagStats'];

        $statsByTag = array_column($enterTagStats, null, 'label');

        $this->assertCount(2, $statsByTag);

        $this->assertEquals(2, $statsByTag['ema_cross']['trades']);
        $this->assertEquals(50.0, $statsByTag['ema_cross']['totalProfit']);

        $this->assertEquals(2, $statsByTag['rsi_confirm']['trades']);
        $this->assertEquals(150.0, $statsByTag['rsi_confirm']['totalProfit']);
    }
}
