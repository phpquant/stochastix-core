<?php

namespace Stochastix\Domain\Order\Model\Pricing;

final readonly class FixedPerUnitCommission implements CommissionInterface
{
    /**
     * The commission rate per unit.
     */
    private string $ratePerUnit;

    /**
     * @param float|string $ratePerUnit the commission rate per unit
     *
     * @throws \InvalidArgumentException if the rate is negative
     */
    public function __construct(float|string $ratePerUnit)
    {
        $rateStr = (string) $ratePerUnit;
        if (bccomp($rateStr, '0') < 0) {
            throw new \InvalidArgumentException('Commission rate per unit cannot be negative.');
        }
        $this->ratePerUnit = $rateStr;
    }

    /**
     * Calculates the commission fee based on the quantity traded.
     * The price parameter is not used for this commission type.
     *
     * @param float|string $quantity the quantity traded
     * @param float|string $price    the price per unit (ignored)
     *
     * @return string the calculated commission fee as a string
     */
    public function calculate(float|string $quantity, float|string $price): string
    {
        $quantityStr = (string) $quantity;

        // Calculate absolute quantity
        $absQuantity = bccomp($quantityStr, '0') < 0
            ? bcsub('0', $quantityStr)
            : $quantityStr;

        // Calculate commission: abs(quantity) * rate_per_unit
        return bcmul($absQuantity, $this->ratePerUnit);
    }
}
