<?php

namespace Stochastix\Tests\Support;

use PHPUnit\Framework\Assert;

trait BcMathAssertionsTrait
{
    /**
     * Asserts that two numbers represented as strings are equal according to bcmath.
     *
     * @param string $expected the expected value
     * @param string $actual   the actual value
     * @param int    $scale    the scale to use for comparison
     * @param string $message  an optional message if the assertion fails
     */
    public static function assertBcEquals(string $expected, string $actual, int $scale = 8, string $message = ''): void
    {
        $comparison = bccomp($expected, $actual, $scale);

        Assert::assertSame(
            0,
            $comparison,
            $message ?: sprintf(
                'Failed asserting that %s is equal to %s (Scale: %d). Comparison result: %d.',
                $actual,
                $expected,
                $scale,
                $comparison
            )
        );
    }

    /**
     * Asserts that one number (string) is less than another according to bcmath.
     *
     * @param string $expectedLess the value expected to be less
     * @param string $expectedMore the value expected to be more
     * @param int    $scale        the scale to use for comparison
     * @param string $message      an optional message if the assertion fails
     */
    public static function assertBcLessThan(string $expectedLess, string $expectedMore, int $scale = 8, string $message = ''): void
    {
        $comparison = bccomp($expectedLess, $expectedMore, $scale);

        Assert::assertSame(
            -1,
            $comparison,
            $message ?: sprintf(
                'Failed asserting that %s is less than %s (Scale: %d). Comparison result: %d.',
                $expectedLess,
                $expectedMore,
                $scale,
                $comparison
            )
        );
    }

    /**
     * Asserts that one number (string) is greater than another according to bcmath.
     *
     * @param string $expectedMore the value expected to be more
     * @param string $expectedLess the value expected to be less
     * @param int    $scale        the scale to use for comparison
     * @param string $message      an optional message if the assertion fails
     */
    public static function assertBcGreaterThan(string $expectedMore, string $expectedLess, int $scale = 8, string $message = ''): void
    {
        $comparison = bccomp($expectedMore, $expectedLess, $scale);

        Assert::assertSame(
            1,
            $comparison,
            $message ?: sprintf(
                'Failed asserting that %s is greater than %s (Scale: %d). Comparison result: %d.',
                $expectedMore,
                $expectedLess,
                $scale,
                $comparison
            )
        );
    }
}
