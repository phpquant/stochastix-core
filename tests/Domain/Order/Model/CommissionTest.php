<?php

namespace Stochastix\Tests\Domain\Order\Model;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stochastix\Domain\Order\Model\Pricing\FixedCommission;
use Stochastix\Domain\Order\Model\Pricing\FixedPerUnitCommission;
use Stochastix\Domain\Order\Model\Pricing\PercentageCommission;
use Stochastix\Tests\Support\BcMathAssertionsTrait;

class CommissionTest extends TestCase
{
    use BcMathAssertionsTrait;

    private const int SCALE = 8;

    // --- DataProvider for PercentageCommission ---
    public static function percentageCommissionProvider(): array
    {
        return [
            'simple integers' => ['0.001', '2', '100', '0.20000000'],
            'float values' => ['0.0015', '1.5', '250.75', '0.56418750'],
            'zero quantity' => ['0.001', '0', '100', '0.00000000'],
            'zero price' => ['0.001', '2', '0', '0.00000000'],
            'zero rate' => ['0', '2', '100', '0.00000000'],
            'string inputs' => ['0.001', '2.0000', '100.0000', '0.20000000'],
            'large numbers' => ['0.00075', '10000', '54321.9876', '407414.90700000'],
        ];
    }

    #[DataProvider('percentageCommissionProvider')]
    public function testPercentageCommissionCalculation(string $rate, string $quantity, string $price, string $expected): void
    {
        $commission = new PercentageCommission($rate);
        $result = $commission->calculate($quantity, $price);
        self::assertBcEquals($expected, $result, self::SCALE);
    }

    public function testPercentageCommissionThrowsOnNegativeRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PercentageCommission('-0.001');
    }

    // --- DataProvider for FixedCommission ---
    public static function fixedCommissionProvider(): array
    {
        return [
            'simple case' => ['5.00', '1', '100', '5.00000000'],
            'different qty/price' => ['5.00', '10.5', '1234.56', '5.00000000'],
            'zero qty/price' => ['2.50', '0', '0', '2.50000000'],
            'string inputs' => ['1.23456789', '1', '1', '1.23456789'],
        ];
    }

    #[DataProvider('fixedCommissionProvider')]
    public function testFixedCommissionCalculation(string $amount, string $quantity, string $price, string $expected): void
    {
        $commission = new FixedCommission($amount);
        $result = $commission->calculate($quantity, $price);
        // Note: The expected value is just the scaled version of the amount
        $scaledExpected = bcadd($expected, '0', self::SCALE);
        self::assertBcEquals($scaledExpected, $result, self::SCALE);
    }

    public function testFixedCommissionThrowsOnNegativeAmount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FixedCommission('-1.0');
    }

    // --- DataProvider for FixedPerUnitCommission ---
    public static function fixedPerUnitCommissionProvider(): array
    {
        return [
            'simple case' => ['0.5', '10', '100', '5.00000000'], // Price is ignored
            'float quantity' => ['0.15', '2.5', '200', '0.37500000'],
            'zero quantity' => ['0.5', '0', '100', '0.00000000'],
            'price is ignored' => ['1.25', '2', '9999', '2.50000000'],
            'string inputs' => ['0.01', '123.45', '1.0', '1.23450000'],
        ];
    }

    #[DataProvider('fixedPerUnitCommissionProvider')]
    public function testFixedPerUnitCommissionCalculation(string $rate, string $quantity, string $price, string $expected): void
    {
        $commission = new FixedPerUnitCommission($rate);
        $result = $commission->calculate($quantity, $price);
        self::assertBcEquals($expected, $result, self::SCALE);
    }

    public function testFixedPerUnitCommissionThrowsOnNegativeRate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new FixedPerUnitCommission('-0.1');
    }
}
