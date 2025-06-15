<?php

namespace Stochastix\Domain\Strategy\Attribute;

use Stochastix\Domain\Common\Enum\TimeframeEnum;

#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsStrategy
{
    /**
     * @param TimeframeEnum[] $requiredMarketData
     */
    public function __construct(
        public string $alias,
        public string $name,
        public ?string $description = null,
        public ?TimeframeEnum $timeframe = null,
        public array $requiredMarketData = [],
    ) {
    }
}
