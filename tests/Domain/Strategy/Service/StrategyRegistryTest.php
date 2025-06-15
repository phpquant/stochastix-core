<?php

namespace Stochastix\Tests\Domain\Strategy\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Common\Model\MultiTimeframeOhlcvSeries;
use Stochastix\Domain\Strategy\Attribute\AsStrategy;
use Stochastix\Domain\Strategy\Attribute\Input;
use Stochastix\Domain\Strategy\Model\StrategyContext;
use Stochastix\Domain\Strategy\Service\StrategyRegistry;
use Stochastix\Domain\Strategy\StrategyInterface;

class StrategyRegistryTest extends TestCase
{
    public function testRegistryBuildsCorrectDefinitionsFromStrategies(): void
    {
        $strategy1 = new MockStrategyOne();
        $strategy2 = new MockStrategyTwo();
        $strategy3 = new MockStrategyWithoutAttribute();

        $registry = new StrategyRegistry([$strategy1, $strategy2, $strategy3]);
        $definitions = $registry->getStrategyDefinitions();

        // Should only find 2 strategies with the #[AsStrategy] attribute
        $this->assertCount(2, $definitions);

        // --- Assertions for MockStrategyOne ---
        $def1 = $definitions[0];
        $this->assertSame('mock_one', $def1->alias);
        $this->assertSame('Mock Strategy One', $def1->name);
        $this->assertSame('A test strategy.', $def1->description);
        $this->assertCount(2, $def1->inputs);

        $input1a = $def1->inputs[0];
        $this->assertSame('someInt', $input1a->name);
        $this->assertSame('An integer input.', $input1a->description);
        $this->assertSame('integer', $input1a->type);
        $this->assertSame(10, $input1a->defaultValue);
        $this->assertSame(1.0, $input1a->min);
        $this->assertNull($input1a->max);

        $input1b = $def1->inputs[1];
        $this->assertSame('someString', $input1b->name);
        $this->assertNull($input1b->description);
        $this->assertSame('string', $input1b->type);
        $this->assertSame('default', $input1b->defaultValue);

        // --- Assertions for MockStrategyTwo ---
        $def2 = $definitions[1];
        $this->assertSame('mock_two', $def2->alias);
        $this->assertSame('Mock Strategy Two', $def2->name);
        $this->assertNull($def2->description);
        $this->assertCount(1, $def2->inputs);

        $input2a = $def2->inputs[0];
        $this->assertSame('aFloat', $input2a->name);
        $this->assertSame('A float input.', $input2a->description);
        $this->assertSame('number', $input2a->type);
        $this->assertSame(0.5, $input2a->defaultValue);
        $this->assertSame(0.1, $input2a->min);
        $this->assertSame(1.0, $input2a->max);
        $this->assertSame(['0.1', '0.5', '1.0'], $input2a->choices);
    }

    public function testGetStrategyReturnsCorrectInstance(): void
    {
        $strategy1 = new MockStrategyOne();
        $strategy2 = new MockStrategyTwo();

        $registry = new StrategyRegistry([$strategy1, $strategy2]);

        $foundStrategy = $registry->getStrategy('mock_one');
        $this->assertInstanceOf(MockStrategyOne::class, $foundStrategy);

        $notFoundStrategy = $registry->getStrategy('non_existent_alias');
        $this->assertNull($notFoundStrategy);
    }

    public function testGetStrategyMetadata(): void
    {
        $strategy1 = new MockStrategyOne();
        $registry = new StrategyRegistry([$strategy1]);

        $metadata = $registry->getStrategyMetadata('mock_one');

        $this->assertInstanceOf(AsStrategy::class, $metadata);
        $this->assertSame('mock_one', $metadata->alias);
        $this->assertSame('Mock Strategy One', $metadata->name);
        $this->assertSame('A test strategy.', $metadata->description);
    }

    public function testGetStrategiesReturnsOriginalIterable(): void
    {
        $strategies = [new MockStrategyOne(), new MockStrategyTwo()];
        $registry = new StrategyRegistry($strategies);

        $this->assertSame($strategies, $registry->getStrategies());
    }

    public function testRegistryWithNoStrategies(): void
    {
        $registry = new StrategyRegistry([]);
        $this->assertEmpty($registry->getStrategyDefinitions());
        $this->assertNull($registry->getStrategy('any'));
    }
}

// --- MOCK CLASSES FOR TESTING ---

#[AsStrategy(alias: 'mock_one', name: 'Mock Strategy One', description: 'A test strategy.')]
class MockStrategyOne implements StrategyInterface
{
    #[Input(description: 'An integer input.', min: 1)]
    private int $someInt = 10;

    #[Input]
    protected string $someString = 'default';

    public function configure(array $runtimeParameters): void
    {
    }

    public function initialize(StrategyContext $context): void
    {
    }

    public function onBar(MultiTimeframeOhlcvSeries $bars): void
    {
    }

    public function getPlotDefinitions(): array
    {
        return [];
    }
}

#[AsStrategy(alias: 'mock_two', name: 'Mock Strategy Two')]
class MockStrategyTwo implements StrategyInterface
{
    #[Input(description: 'A float input.', min: 0.1, max: 1.0, choices: ['0.1', '0.5', '1.0'])]
    public float $aFloat = 0.5;

    public function configure(array $runtimeParameters): void
    {
    }

    public function initialize(StrategyContext $context): void
    {
    }

    public function onBar(MultiTimeframeOhlcvSeries $bars): void
    {
    }

    public function getPlotDefinitions(): array
    {
        return [];
    }
}

class MockStrategyWithoutAttribute implements StrategyInterface
{
    public function configure(array $runtimeParameters): void
    {
    }

    public function initialize(StrategyContext $context): void
    {
    }

    public function onBar(MultiTimeframeOhlcvSeries $bars): void
    {
    }

    public function getPlotDefinitions(): array
    {
        return [];
    }
}
