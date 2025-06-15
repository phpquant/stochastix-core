<?php

namespace Stochastix\Tests\Domain\Common\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Common\Util\Math;

class MathTest extends TestCase
{
    private const int DEFAULT_SCALE = 8;

    // --- DataProvider for testMean ---
    public static function meanProvider(): array
    {
        return [
            'basic set' => [['10.0', '12.0', '23.0', '23.0', '16.0', '23.0', '21.0', '16.0'], self::DEFAULT_SCALE, '18.00000000'],
            'scaled numbers' => [['1.12345', '2.23456', '3.34567'], self::DEFAULT_SCALE, '2.23456000'],
            'empty array' => [[], self::DEFAULT_SCALE, '0.00000000'],
            'single value' => [['10.123'], self::DEFAULT_SCALE, '10.12300000'],
            'negative numbers' => [['-1.0', '-2.0', '-3.0'], self::DEFAULT_SCALE, '-2.00000000'],
            'different scale' => [['10', '20'], 2, '15.00'],
        ];
    }

    #[DataProvider('meanProvider')]
    public function testMean(array $values, int $scale, string $expected)
    {
        $this->assertSame($expected, Math::mean($values, $scale));
    }

    // --- DataProvider for testCovariance ---
    public static function covarianceProvider(): array
    {
        $scale = self::DEFAULT_SCALE;
        // Example from https://www.statisticshowto.com/probability-and-statistics/statistics-definitions/covariance/
        $x = ['2.1', '2.5', '3.6', '4.0'];
        $y = ['8', '10', '12', '14'];

        return [
            'basic set' => [$x, $y, $scale, '2.26666666'],
            'identical sets (is variance)' => [['2', '4', '6'], ['2', '4', '6'], $scale, '4.00000000'],
            'negatively correlated' => [['10', '8', '6'], ['2', '4', '6'], $scale, '-4.00000000'],
            'uncorrelated' => [['1', '2', '3'], ['3', '1', '2'], $scale, '-0.50000000'],
            'not enough data points' => [['1'], ['2'], $scale, '0.00000000'],
            'unequal data sets' => [['1', '2'], ['3'], $scale, '0.00000000'],
        ];
    }

    #[DataProvider('covarianceProvider')]
    public function testCovariance(array $values1, array $values2, int $scale, string $expected)
    {
        $this->assertSame($expected, Math::covariance($values1, $values2, $scale));
    }

    // --- DataProvider for testVariance ---
    public static function varianceProvider(): array
    {
        $scale = self::DEFAULT_SCALE;
        $values1 = ['2', '4', '4', '4', '5', '5', '7', '9']; // Wikipedia example

        return [
            'wikipedia sample' => [$values1, $scale, true, '4.57142857'],
            'wikipedia population' => [$values1, $scale, false, '4.00000000'],
            'all same numbers sample' => [['5.0', '5.0', '5.0', '5.0'], $scale, true, '0.00000000'],
            'all same numbers population' => [['5.0', '5.0', '5.0', '5.0'], $scale, false, '0.00000000'],
            'less than 2 for sample' => [['10.0'], $scale, true, '0.00000000'],
            'empty for sample' => [[], $scale, true, '0.00000000'],
            'empty for population' => [[], $scale, false, '0.00000000'],
            'single for population' => [['10.0'], $scale, false, '0.00000000'], // Population variance for single value is 0
            'two values sample' => [['1.0', '3.0'], $scale, true, '2.00000000'], // ((1-2)^2 + (3-2)^2)/(2-1) = (1+1)/1=2
            'two values population' => [['1.0', '3.0'], $scale, false, '1.00000000'], // ((1-2)^2 + (3-2)^2)/2 = (1+1)/2=1
        ];
    }

    #[DataProvider('varianceProvider')]
    public function testVariance(array $values, int $scale, bool $sample, string $expected)
    {
        $this->assertSame($expected, Math::variance($values, $scale, $sample));
    }

    // --- DataProvider for testStandardDeviation ---
    public static function standardDeviationProvider(): array
    {
        $scale = self::DEFAULT_SCALE;
        $values1 = ['2', '4', '4', '4', '5', '5', '7', '9']; // Wikipedia example

        return [
            'wikipedia sample' => [$values1, $scale, true, '2.13808993'], // sqrt(4.57142857)
            'wikipedia population' => [$values1, $scale, false, '2.00000000'], // sqrt(4)
            'all same numbers sample' => [['5.0', '5.0', '5.0', '5.0'], $scale, true, '0.00000000'],
            'less than 2 for sample' => [['10.0'], $scale, true, '0.00000000'],
            'empty for sample' => [[], $scale, true, '0.00000000'],
            'two values sample' => [['1.0', '3.0'], $scale, true, '1.41421356'], // sqrt(2)
            'two values population' => [['1.0', '3.0'], $scale, false, '1.00000000'], // sqrt(1)
        ];
    }

    #[DataProvider('standardDeviationProvider')]
    public function testStandardDeviation(array $values, int $scale, bool $sample, string $expected)
    {
        $this->assertSame($expected, Math::standardDeviation($values, $scale, $sample));
    }
}
