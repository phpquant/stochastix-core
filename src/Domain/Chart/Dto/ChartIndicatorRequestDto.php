<?php

namespace Stochastix\Domain\Chart\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Data Transfer Object representing a request to fetch chart data
 * along with on-demand calculated indicators.
 */
final readonly class ChartIndicatorRequestDto
{
    /**
     * @param string $exchangeId The exchange identifier (e.g., "binance").
     * @param string $symbol The trading symbol (e.g., "BTC/USDT").
     * @param string $timeframe The chart timeframe (e.g., "1h", "4h").
     * @param int|null $fromTimestamp Optional start of the visible data window (Unix timestamp).
     * @param int|null $toTimestamp Optional end of the visible data window (Unix timestamp).
     * @param int|null $countback Optional number of bars to fetch counting backwards from toTimestamp.
     * @param IndicatorRequest[] $indicators The list of indicators to calculate.
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $exchangeId,
        #[Assert\NotBlank]
        public string $symbol,
        #[Assert\NotBlank]
        public string $timeframe,
        public ?int $fromTimestamp = null,
        public ?int $toTimestamp = null,
        #[Assert\Positive]
        public ?int $countback = 1000,
        #[Assert\Valid]
        #[Assert\All(new Assert\Type(type: IndicatorRequest::class))]
        public array $indicators = [],
    ) {
    }
}
