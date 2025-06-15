<?php

namespace Stochastix\Domain\Order\Model\Pricing;

final readonly class PercentageCommission implements CommissionInterface
{
    /**
     * The commission rate (e.g., "0.001" for 0.1%).
     */
    private string $rate;

    /**
     * @param float|string $rate the commission rate
     *
     * @throws \InvalidArgumentException if the rate is negative
     */
    public function __construct(float|string $rate)
    {
        $rateStr = (string) $rate;

        // Ensure bcscale is set for bccomp, or provide it explicitly
        if (bccomp($rateStr, '0') < 0) {
            throw new \InvalidArgumentException('Commission rate cannot be negative.');
        }
        $this->rate = $rateStr;
    }

    /**
     * Calculates the commission based on a percentage of the trade value.
     *
     * @param float|string $quantity the quantity traded
     * @param float|string $price    the price per unit
     *
     * @return string the calculated commission fee as a string
     */
    public function calculate(float|string $quantity, float|string $price): string
    {
        $quantityStr = (string) $quantity;
        $priceStr = (string) $price;

        // Calculate absolute quantity using bccomp and bcsub if negative
        $absQuantity = bccomp($quantityStr, '0') < 0
            ? bcsub('0', $quantityStr)
            : $quantityStr;

        // Calculate the total trade value: abs(quantity) * price
        $tradeValue = bcmul($absQuantity, $priceStr);

        // Calculate the commission: trade_value * rate
        return bcmul($tradeValue, $this->rate);
    }
}
