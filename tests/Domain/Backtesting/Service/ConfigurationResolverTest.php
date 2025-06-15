<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Service\ConfigurationResolver;
use Stochastix\Domain\Common\Enum\TimeframeEnum;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Stochastix\Domain\Strategy\StrategyInterface;
use Symfony\Component\Console\Input\InputInterface;

class ConfigurationResolverTest extends TestCase
{
    private StrategyRegistryInterface $strategyRegistryMock;
    private InputInterface $inputMock;
    private array $globalDefaults;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategyRegistryMock = $this->createMock(StrategyRegistryInterface::class);
        $this->inputMock = $this->createMock(InputInterface::class);

        // Mock the getStrategy method to always return a valid strategy mock
        $this->strategyRegistryMock->method('getStrategy')
            ->willReturn($this->createMock(StrategyInterface::class));

        $this->globalDefaults = [
            'initial_capital' => 10000.0,
            'stake_currency' => 'USDT',
            'timeframe' => '1d',
            'symbols' => ['BTC/USDT'],
            'commission' => ['type' => 'percentage', 'rate' => '0.001'],
            'data_source' => ['type' => 'stchx_binary', 'exchange_id' => 'binance'],
        ];
    }

    private function createResolver(?array $defaults = null): ConfigurationResolver
    {
        return new ConfigurationResolver($this->strategyRegistryMock, $defaults ?? $this->globalDefaults);
    }

    public function testResolveWithCliOverrides(): void
    {
        $this->inputMock->method('getArgument')->with('strategy-alias')->willReturn('test_strat');
        $this->inputMock->method('getOption')->willReturnMap([
            ['symbol', ['ETH/USDT']], // Override symbol
            ['timeframe', '4h'],       // Override timeframe
            ['start-date', '2024-01-01'],
            ['end-date', null],
            ['input', ['ema:50']],     // Override strategy input
            ['config', ['initial_capital:99999']], // Override global config
            ['load-config', null],
        ]);

        $resolver = $this->createResolver();
        $config = $resolver->resolve($this->inputMock);

        $this->assertEquals(['ETH/USDT'], $config->symbols);
        $this->assertEquals(TimeframeEnum::H4, $config->timeframe);
        $this->assertEquals(new \DateTimeImmutable('2024-01-01'), $config->startDate);
        $this->assertEquals(['ema' => '50'], $config->strategyInputs);
        $this->assertEquals('99999', $config->initialCapital);
    }

    public function testResolveUsesGlobalDefaults(): void
    {
        $this->inputMock->method('getArgument')->with('strategy-alias')->willReturn('test_strat');
        // No CLI overrides provided for these options
        $this->inputMock->method('getOption')->willReturnMap([
            ['symbol', []],
            ['timeframe', null],
            ['start-date', null],
            ['end-date', null],
            ['input', []],
            ['config', []],
            ['load-config', null],
        ]);

        $resolver = $this->createResolver();
        $config = $resolver->resolve($this->inputMock);

        $this->assertEquals($this->globalDefaults['symbols'], $config->symbols);
        $this->assertEquals(TimeframeEnum::from($this->globalDefaults['timeframe']), $config->timeframe);
        $this->assertEquals($this->globalDefaults['initial_capital'], $config->initialCapital);
    }

    public function testThrowsExceptionForMissingRequiredConfig(): void
    {
        $this->inputMock->method('getArgument')->with('strategy-alias')->willReturn('test_strat');
        $this->inputMock->method('getOption')->willReturn([]);

        // Create resolver with empty defaults
        $resolver = $this->createResolver([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing required configuration parameter: 'symbols'");

        $resolver->resolve($this->inputMock);
    }
}
