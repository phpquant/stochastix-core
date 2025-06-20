<?php

namespace Stochastix\Domain\Chart\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Data Transfer Object representing the configuration for a single
 * indicator to be displayed on a chart.
 */
final readonly class IndicatorRequest
{
    /**
     * @param string $key A unique identifier for this indicator instance on the chart (e.g., "ema_fast").
     * @param string $type The type of the indicator, used to determine how to instantiate it (e.g., "talib").
     * @param string $function The specific function to call (e.g., "ema", "rsi").
     * @param array<string, mixed> $params The parameters for the indicator function (e.g., ["timePeriod" => 20]).
     * @param string $source The OHLCV field to use as input (e.g., "close", "high").
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $key,
        #[Assert\NotBlank]
        public string $type,
        #[Assert\NotBlank]
        public string $function,
        public array $params = [],
        public string $source = 'close',
    ) {
    }
}
