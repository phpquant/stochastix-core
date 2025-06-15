<?php

namespace Stochastix\Tests\Support;

use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Order\Dto\ExecutionResult;

trait TestDataFactoryTrait
{
    /**
     * Creates an ExecutionResult DTO for testing.
     *
     * @param string $commissionAsset Defaults to 'USDT'
     */
    protected function createExecutionResult(
        string $symbol,
        DirectionEnum $direction,
        string $price,
        string $quantity,
        string $commission,
        string $commissionAsset = 'USDT',
        ?string $orderId = null,
        ?string $clientOrderId = null,
        ?\DateTimeImmutable $time = null,
        ?array $enterTags = null,
        ?array $exitTags = null
    ): ExecutionResult {
        return new ExecutionResult(
            orderId: $orderId ?? 'ord-' . uniqid('', true),
            clientOrderId: $clientOrderId,
            symbol: $symbol,
            direction: $direction,
            filledPrice: $price,
            filledQuantity: $quantity,
            commissionAmount: $commission,
            commissionAsset: $commissionAsset,
            executedAt: $time ?? new \DateTimeImmutable(),
            enterTags: $enterTags,
            exitTags: $exitTags
        );
    }
}
