<?php

namespace Stochastix\Domain\Order\Model\Pricing;

final readonly class FixedCommission implements CommissionInterface
{
    /**
     * The fixed commission amount per trade.
     */
    private string $amount;

    /**
     * @param float|string $amount the fixed commission amount
     *
     * @throws \InvalidArgumentException if the amount is negative
     */
    public function __construct(float|string $amount)
    {
        $amountStr = (string) $amount;

        if (bccomp($amountStr, '0') < 0) {
            throw new \InvalidArgumentException('Fixed commission amount cannot be negative.');
        }
        $this->amount = $amountStr;
    }

    /**
     * Returns the fixed commission amount, ignoring quantity and price.
     *
     * @param float|string $quantity the quantity traded (ignored)
     * @param float|string $price    the price per unit (ignored)
     *
     * @return string the fixed commission fee as a string
     */
    public function calculate(float|string $quantity, float|string $price): string
    {
        return $this->amount;
    }
}
