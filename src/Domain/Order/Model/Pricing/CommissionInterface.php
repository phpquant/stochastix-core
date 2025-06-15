<?php

namespace Stochastix\Domain\Order\Model\Pricing;

interface CommissionInterface
{
    /**
     * Calculates the commission fee for a trade.
     *
     * @param float|string $quantity the quantity of the asset traded (as float or string)
     * @param float|string $price    the price per unit of the asset (as float or string)
     *
     * @return string the calculated commission fee as a string for arbitrary precision
     */
    public function calculate(float|string $quantity, float|string $price): string;
}
