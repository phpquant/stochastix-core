<?php

namespace Stochastix\Domain\Common\Util;

final class Math
{
    /**
     * Calculates the arithmetic mean of an array of string numbers.
     *
     * @param string[] $stringValues an array of numbers as strings
     * @param int      $scale        the scale for bcmath operations
     *
     * @return string the mean as a string, or '0' if the array is empty
     */
    public static function mean(array $stringValues, int $scale): string
    {
        $count = count($stringValues);
        if ($count === 0) {
            return bcadd('0', '0', $scale); // Return '0' scaled correctly
        }

        $sum = '0';
        foreach ($stringValues as $value) {
            $sum = bcadd($sum, $value, $scale + 2); // Use higher internal precision for sum
        }

        return bcdiv($sum, (string) $count, $scale);
    }

    /**
     * Calculates the covariance between two arrays of string numbers.
     *
     * @param string[] $stringValues1
     * @param string[] $stringValues2
     * @param bool     $sample        true for sample covariance (n-1), false for population (n)
     */
    public static function covariance(array $stringValues1, array $stringValues2, int $scale, bool $sample = true): string
    {
        $count = count($stringValues1);
        if ($count !== count($stringValues2) || ($sample && $count < 2) || (!$sample && $count < 1)) {
            return bcadd('0', '0', $scale);
        }

        $internalScale = $scale + 4;
        $mean1 = self::mean($stringValues1, $internalScale);
        $mean2 = self::mean($stringValues2, $internalScale);

        $sumOfProducts = '0';
        for ($i = 0; $i < $count; ++$i) {
            $dev1 = bcsub($stringValues1[$i], $mean1, $internalScale);
            $dev2 = bcsub($stringValues2[$i], $mean2, $internalScale);
            $sumOfProducts = bcadd($sumOfProducts, bcmul($dev1, $dev2, $internalScale), $internalScale);
        }

        $denominator = $sample ? (string) ($count - 1) : (string) $count;
        if (bccomp($denominator, '0', 0) === 0) {
            return bcadd('0', '0', $scale);
        }

        return bcdiv($sumOfProducts, $denominator, $scale);
    }

    /**
     * Calculates the variance of an array of string numbers.
     *
     * @param string[] $stringValues an array of numbers as strings
     * @param int      $scale        the scale for bcmath operations
     * @param bool     $sample       true for sample variance (N-1 denominator), false for population variance (N denominator)
     *
     * @return string the variance as a string, or '0' if not calculable
     */
    public static function variance(array $stringValues, int $scale, bool $sample = true): string
    {
        $count = count($stringValues);

        if (($sample && $count < 2) || (!$sample && $count < 1)) {
            return bcadd('0', '0', $scale); // Variance undefined or 0
        }

        // Use a higher intermediate scale for precision
        $internalScale = $scale + 4;
        $mean = self::mean($stringValues, $internalScale);

        $sumOfSquares = '0';
        foreach ($stringValues as $value) {
            $deviation = bcsub($value, $mean, $internalScale);
            $sumOfSquares = bcadd($sumOfSquares, bcpow($deviation, '2', $internalScale), $internalScale);
        }

        $denominator = $sample ? (string) ($count - 1) : (string) $count;

        if (bccomp($denominator, '0', 0) === 0) { // Should not happen if count checks are correct
            return bcadd('0', '0', $scale);
        }

        return bcdiv($sumOfSquares, $denominator, $scale);
    }

    /**
     * Calculates the standard deviation of an array of string numbers.
     *
     * @param string[] $stringValues an array of numbers as strings
     * @param int      $scale        the scale for bcmath operations
     * @param bool     $sample       true for sample standard deviation, false for population
     *
     * @return string the standard deviation as a string, or '0'
     */
    public static function standardDeviation(array $stringValues, int $scale, bool $sample = true): string
    {
        $count = count($stringValues);
        if (($sample && $count < 2) || (!$sample && $count < 1)) {
            return bcadd('0', '0', $scale);
        }

        $variance = self::variance($stringValues, $scale + 4, $sample); // Calculate variance with higher precision

        if (bccomp($variance, '0', $scale + 4) < 0) {
            // This should ideally not happen with sum of squares, but as a safeguard
            return bcadd('0', '0', $scale);
        }
        if (bccomp($variance, '0', $scale + 4) === 0) {
            return bcadd('0', '0', $scale);
        }

        return bcsqrt($variance, $scale);
    }
}
