<?php

namespace Stochastix\Tests\Domain\Backtesting\Service\Metric;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Backtesting\Service\Metric\AbstractSeriesMetric;

// --- Test stubs for the DataProvider ---

/**
 * A stub implementation of AbstractSeriesMetric for testing purposes.
 */
class SimpleTestMetric extends AbstractSeriesMetric
{
    public function calculate(array $backtestResults): void
    {
        // This method is not tested here, so its implementation is not needed.
    }
}

/**
 * A stub with a multi-word name to test camelCase conversion.
 */
class TwoWordTestMetric extends AbstractSeriesMetric
{
    public function calculate(array $backtestResults): void
    {
    }
}

/**
 * A stub with an acronym to test camelCase conversion edge cases.
 */
class AWPTestMetric extends AbstractSeriesMetric
{
    public function calculate(array $backtestResults): void
    {
    }
}

/**
 * Unit test for the AbstractSeriesMetric class.
 */
class AbstractSeriesMetricTest extends TestCase
{
    // --- DataProvider for testing the getName() method ---

    public static function nameProvider(): array
    {
        return [
            'simple name' => ['simpleTestMetric', new SimpleTestMetric()],
            'two word name' => ['twoWordTestMetric', new TwoWordTestMetric()],
            'acronym name' => ['AWPTestMetric', new AWPTestMetric()],
        ];
    }

    #[DataProvider('nameProvider')]
    public function testGetName(string $expectedName, AbstractSeriesMetric $metric): void
    {
        $this->assertSame($expectedName, $metric->getName());
    }

    // --- Tests for the getValues() method ---

    public function testGetValuesThrowsExceptionWhenNotCalculated(): void
    {
        $metric = new SimpleTestMetric();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Metric Series "Stochastix\Tests\Domain\Backtesting\Service\Metric\SimpleTestMetric" has not been calculated yet. Call calculate() first.');

        $metric->getValues();
    }

    public static function valuesProvider(): array
    {
        return [
            'non-empty array' => [[1.0, 1.1, 1.2]],
            'empty array' => [[]],
            'array with nulls' => [[1.0, null, 3.0]],
        ];
    }

    #[DataProvider('valuesProvider')]
    public function testGetValuesReturnsCorrectData(array $testValues): void
    {
        $metric = new SimpleTestMetric();

        // Use reflection to set the protected $values property
        $reflection = new \ReflectionClass(AbstractSeriesMetric::class);
        $valuesProperty = $reflection->getProperty('values');
        $valuesProperty->setValue($metric, $testValues);

        $this->assertSame($testValues, $metric->getValues());
    }
}
