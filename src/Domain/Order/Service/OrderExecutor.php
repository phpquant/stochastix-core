<?php

namespace Stochastix\Domain\Order\Service;

use Stochastix\Domain\Common\Model\OhlcvSeries;
use Stochastix\Domain\Order\Dto\ExecutionResult;
use Stochastix\Domain\Order\Dto\OrderSignal;
use Stochastix\Domain\Order\Model\Pricing\CommissionInterface;

final readonly class OrderExecutor implements OrderExecutorInterface
{
    public function __construct(
        private CommissionInterface $commission
    ) {
    }

    public function execute(
        OrderSignal $signal,
        OhlcvSeries $currentBarData,
        \DateTimeImmutable $executionTime
    ): ?ExecutionResult {
        // If the signal has a specific price, use it (for SL/TP/Limit orders).
        // Otherwise, default to the bar's OPEN price for a standard market order.
        $fillPrice = $signal->price ?? $currentBarData->open[0];

        if ($fillPrice === null) {
            // Cannot execute if there's no valid price
            return null;
        }

        $fillPriceStr = (string) $fillPrice;
        $quantityStr = $signal->quantity;
        $commissionAmount = $this->commission->calculate($quantityStr, $fillPriceStr);
        $commissionAsset = 'USDT';

        // Create ExecutionResult with all required values
        return new ExecutionResult(
            orderId: uniqid('exec_', true),
            clientOrderId: $signal->clientOrderId,
            symbol: $signal->symbol,
            direction: $signal->direction,
            filledPrice: $fillPriceStr,
            filledQuantity: $quantityStr,
            commissionAmount: $commissionAmount,
            commissionAsset: $commissionAsset,
            executedAt: $executionTime,
            stopLossPrice: $signal->stopLossPrice,
            takeProfitPrice: $signal->takeProfitPrice,
            enterTags: $signal->enterTags,
            exitTags: $signal->exitTags
        );
    }
}
