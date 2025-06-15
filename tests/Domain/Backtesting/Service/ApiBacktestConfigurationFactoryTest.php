<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Dto\LaunchBacktestRequestDto;
use Stochastix\Domain\Backtesting\Service\ApiBacktestConfigurationFactory;
use Stochastix\Domain\Common\Enum\TimeframeEnum;
use Stochastix\Domain\Strategy\Attribute\AsStrategy;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Stochastix\Domain\Strategy\StrategyInterface;

class ApiBacktestConfigurationFactoryTest extends TestCase
{
    private StrategyRegistryInterface $strategyRegistryMock;
    private array $globalDefaults;

    protected function setUp(): void
    {
        parent::setUp();
        $this->strategyRegistryMock = $this->createMock(StrategyRegistryInterface::class);
        $this->globalDefaults = [
            'commission' => [
                'type' => 'percentage',
                'rate' => '0.001',
            ],
            'data_source' => [
                'type' => 'stchx_binary',
                'stchx_binary_options' => ['some_default_option' => true],
            ],
        ];
    }

    private function createFactory(): ApiBacktestConfigurationFactory
    {
        return new ApiBacktestConfigurationFactory($this->strategyRegistryMock, $this->globalDefaults);
    }

    public function testCreateWithBasicRequest(): void
    {
        $strategyAlias = 'test_strategy';
        $mockStrategy = $this->createMock(StrategyInterface::class);
        $mockMetadata = new AsStrategy(alias: $strategyAlias, name: 'Test Strategy');

        $this->strategyRegistryMock->method('getStrategy')
            ->with($strategyAlias)
            ->willReturn($mockStrategy);

        $this->strategyRegistryMock->method('getStrategyMetadata')
            ->with($strategyAlias)
            ->willReturn($mockMetadata);

        $requestDto = new LaunchBacktestRequestDto(
            strategyAlias: $strategyAlias,
            symbols: ['BTC/USDT'],
            timeframe: '1d',
            startDate: '2024-01-01',
            endDate: '2024-01-31',
            initialCapital: '50000',
            dataSourceExchangeId: 'binance',
            inputs: ['ema' => 20]
        );

        $factory = $this->createFactory();
        $config = $factory->create($requestDto);

        $this->assertInstanceOf(BacktestConfiguration::class, $config);
        $this->assertSame($strategyAlias, $config->strategyAlias);
        $this->assertSame(get_class($mockStrategy), $config->strategyClass);
        $this->assertEquals(['BTC/USDT'], $config->symbols);
        $this->assertEquals(TimeframeEnum::D1, $config->timeframe);
    }

    public function testCreateMergesGlobalDefaultsForCommission(): void
    {
        $strategyAlias = 'test_strategy';
        $mockStrategy = $this->createMock(StrategyInterface::class);
        $mockMetadata = new AsStrategy(alias: $strategyAlias, name: 'Test Strategy');

        $this->strategyRegistryMock->method('getStrategy')->willReturn($mockStrategy);
        $this->strategyRegistryMock->method('getStrategyMetadata')->willReturn($mockMetadata);

        $requestDto = new LaunchBacktestRequestDto(
            strategyAlias: $strategyAlias,
            symbols: ['ETH/USDT'],
            timeframe: '4h',
            startDate: null,
            endDate: null,
            initialCapital: '1000',
            dataSourceExchangeId: 'okx'
        );

        $factory = $this->createFactory();
        $config = $factory->create($requestDto);

        $this->assertEquals($this->globalDefaults['commission'], $config->commissionConfig);
    }

    public function testCreateRequestOverridesDefaults(): void
    {
        $strategyAlias = 'test_strategy';
        $mockStrategy = $this->createMock(StrategyInterface::class);
        $mockMetadata = new AsStrategy(alias: $strategyAlias, name: 'Test Strategy');

        $this->strategyRegistryMock->method('getStrategy')->willReturn($mockStrategy);
        $this->strategyRegistryMock->method('getStrategyMetadata')->willReturn($mockMetadata);

        $requestCommission = [
            'type' => 'fixed_per_trade',
            'amount' => '1.50',
        ];

        $requestDto = new LaunchBacktestRequestDto(
            strategyAlias: 'test_strategy',
            symbols: ['ETH/USDT'],
            timeframe: '4h',
            startDate: null,
            endDate: null,
            initialCapital: '1000',
            dataSourceExchangeId: 'okx',
            commissionConfig: $requestCommission
        );

        $factory = $this->createFactory();
        $config = $factory->create($requestDto);

        $this->assertEquals($requestCommission, $config->commissionConfig);
    }

    public function testCreateThrowsExceptionForInvalidStrategyAlias(): void
    {
        $strategyAlias = 'unknown_strategy';

        $this->strategyRegistryMock->method('getStrategy')
            ->with($strategyAlias)
            ->willReturn(null);

        $this->strategyRegistryMock->method('getStrategyMetadata')
            ->with($strategyAlias)
            ->willReturn(null);

        $requestDto = new LaunchBacktestRequestDto(
            strategyAlias: $strategyAlias,
            symbols: ['BTC/USDT'],
            timeframe: '1d',
            startDate: null,
            endDate: null,
            initialCapital: '10000',
            dataSourceExchangeId: 'binance'
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Strategy with alias '{$strategyAlias}' not found.");

        $factory = $this->createFactory();
        $factory->create($requestDto);
    }
}
