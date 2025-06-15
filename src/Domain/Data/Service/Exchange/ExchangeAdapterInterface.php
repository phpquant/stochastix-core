<?php

declare(strict_types=1);

namespace Stochastix\Domain\Data\Service\Exchange;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface ExchangeAdapterInterface
{
    public function supportsExchange(string $exchangeId): bool;

    public function fetchOhlcv(
        string $exchangeId,
        string $symbol,
        string $timeframe,
        \DateTimeImmutable $startTime,
        \DateTimeImmutable $endTime
    ): \Generator;
}
