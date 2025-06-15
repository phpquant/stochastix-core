<?php

namespace Stochastix\Tests\Domain\Backtesting\Service;

use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Service\Metric\DependentSeriesMetricInterface;
use Stochastix\Domain\Backtesting\Service\Metric\SeriesMetricInterface;
use Stochastix\Domain\Backtesting\Service\SeriesMetricService;

// --- Test Stubs ---

// A metric with no dependencies
class StubMetricA implements SeriesMetricInterface
{
    public int $calculateCallCount = 0;

    public function getName(): string
    {
        return 'metricA';
    }

    public function calculate(array $backtestResults): void
    {
        ++$this->calculateCallCount;
    }

    public function getValues(): array
    {
        return [1, 2];
    }
}

// A metric that depends on Metric A
class StubMetricB implements DependentSeriesMetricInterface
{
    public int $setDependencyCallCount = 0;
    public array $dependencyResults;

    public function getName(): string
    {
        return 'metricB';
    }

    public function getDependencies(): array
    {
        return [StubMetricA::class];
    }

    public function setDependencyResults(array $results): void
    {
        ++$this->setDependencyCallCount;
        $this->dependencyResults = $results;
    }

    public function calculate(array $backtestResults): void
    {
    }

    public function getValues(): array
    {
        return [3, 4];
    }
}

// A metric that depends on Metric B
class StubMetricC implements DependentSeriesMetricInterface
{
    public function getName(): string
    {
        return 'metricC';
    }

    public function getDependencies(): array
    {
        return [StubMetricB::class];
    }

    public function setDependencyResults(array $results): void
    {
    }

    public function calculate(array $backtestResults): void
    {
    }

    public function getValues(): array
    {
        return [5, 6];
    }
}

// Circular dependency stubs
class StubMetricCircularA implements DependentSeriesMetricInterface
{
    public function getName(): string
    {
        return 'circA';
    }

    public function getDependencies(): array
    {
        return [StubMetricCircularB::class];
    }

    public function setDependencyResults(array $results): void
    {
    }

    public function calculate(array $backtestResults): void
    {
    }

    public function getValues(): array
    {
        return [];
    }
}
class StubMetricCircularB implements DependentSeriesMetricInterface
{
    public function getName(): string
    {
        return 'circB';
    }

    public function getDependencies(): array
    {
        return [StubMetricCircularA::class];
    }

    public function setDependencyResults(array $results): void
    {
    }

    public function calculate(array $backtestResults): void
    {
    }

    public function getValues(): array
    {
        return [];
    }
}

class SeriesMetricServiceTest extends TestCase
{
    public function testCalculateExecutesInCorrectTopologicalOrderAndPassesDependencies(): void
    {
        // Use concrete stubs instead of mocks to avoid potential reflection issues
        $metricA = new StubMetricA();
        $metricB = new StubMetricB();
        $metricC = new StubMetricC();

        // The service should figure out the correct order: A -> B -> C,
        // even though we pass them in a jumbled order.
        $service = new SeriesMetricService([$metricC, $metricA, $metricB]);
        $results = $service->calculate([]);

        // Assert final formatted output
        $expectedResults = [
            'metricA' => ['value' => [1, 2]],
            'metricB' => ['value' => [3, 4]],
            'metricC' => ['value' => [5, 6]],
        ];
        $this->assertEquals($expectedResults, $results);

        // Assert that dependencies were correctly passed from A to B
        $this->assertSame(1, $metricB->setDependencyCallCount);
        $this->assertArrayHasKey(StubMetricA::class, $metricB->dependencyResults);
        $this->assertEquals(['values' => [1, 2]], $metricB->dependencyResults[StubMetricA::class]);

        // Assert that calculate was called on the metric without dependencies
        $this->assertSame(1, $metricA->calculateCallCount);
    }

    public function testCalculateThrowsExceptionOnCircularDependency(): void
    {
        $metricA = new StubMetricCircularA();
        $metricB = new StubMetricCircularB();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A circular dependency was detected in the Series Metrics.');

        $service = new SeriesMetricService([$metricA, $metricB]);
        $service->calculate([]);
    }
}
