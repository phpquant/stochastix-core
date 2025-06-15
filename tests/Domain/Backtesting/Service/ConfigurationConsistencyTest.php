<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use Stochastix\Domain\Backtesting\Dto\LaunchBacktestRequestDto;
use Stochastix\Domain\Backtesting\Service\ApiBacktestConfigurationFactory;
use Stochastix\Domain\Backtesting\Service\ConfigurationResolver;
use Stochastix\Domain\Strategy\Attribute\AsStrategy;
use Stochastix\Domain\Strategy\Service\StrategyRegistryInterface;
use Stochastix\Domain\Strategy\StrategyInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Ensures that the configuration created via the API and CLI flows are consistent,
 * especially regarding default values.
 */
class ConfigurationConsistencyTest extends KernelTestCase
{
    private ConfigurationResolver $cliConfigResolver;
    private ApiBacktestConfigurationFactory $apiConfigFactory;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $strategyRegistryMock = $this->createMock(StrategyRegistryInterface::class);
        $strategyRegistryMock->method('getStrategy')
            ->willReturn($this->createMock(StrategyInterface::class));

        // Add mock for the getStrategyMetadata method
        $strategyRegistryMock->method('getStrategyMetadata')
            ->willReturn(new AsStrategy(alias: 'test_strat', name: 'Test Strategy'));

        $container->set(StrategyRegistryInterface::class, $strategyRegistryMock);

        $this->cliConfigResolver = $container->get(ConfigurationResolver::class);
        $this->apiConfigFactory = $container->get(ApiBacktestConfigurationFactory::class);
    }

    /**
     * This test simulates the scenario that caused the original bug.
     * It verifies that when the API request omits the `dataSourceExchangeId`,
     * the system correctly falls back to the same default value used by the CLI.
     */
    public function testApiFallsBackToDefaultExchangeIdLikeCli(): void
    {
        // 1. Setup CLI input simulation (no exchange override)
        $cliInputMock = $this->createMock(InputInterface::class);
        $cliInputMock->method('getArgument')->with('strategy-alias')->willReturn('test_strat');
        $cliInputMock->method('getOption')->willReturnMap([
            ['symbol', ['BTC/USDT']],
            ['timeframe', '1d'],
            ['start-date', null],
            ['end-date', null],
            ['input', []],
            ['config', []],
            ['load-config', null],
        ]);

        // 2. Setup API DTO, simulating the UI *not* sending the exchange ID.
        $apiDto = new LaunchBacktestRequestDto(
            strategyAlias: 'test_strat',
            symbols: ['BTC/USDT'],
            timeframe: '1d',
            startDate: null,
            endDate: null,
            initialCapital: '10000',
            dataSourceExchangeId: null
        );

        // 3. Resolve configurations from both flows.
        $cliConfig = $this->cliConfigResolver->resolve($cliInputMock);
        $apiConfig = $this->apiConfigFactory->create($apiDto);

        // 4. Assert consistency.
        $this->assertNotNull($cliConfig->dataSourceExchangeId, 'CLI config should have a default exchange ID.');
        $this->assertNotNull($apiConfig->dataSourceExchangeId, 'API config should have a fallback exchange ID.');

        $this->assertSame(
            $cliConfig->dataSourceExchangeId,
            $apiConfig->dataSourceExchangeId,
            'API configuration should fall back to the same default exchange ID used by the CLI.'
        );

        $this->assertSame('binance', $apiConfig->dataSourceExchangeId, "The fallback exchange ID should be 'binance'.");
    }
}
