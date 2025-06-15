<?php

namespace Stochastix\Domain\Strategy\Dto;

/**
 * Data Transfer Object for a complete strategy definition, including its inputs.
 */
final readonly class StrategyDefinitionDto
{
    /**
     * @param string                    $alias              the unique alias of the strategy
     * @param string                    $name               the human-readable name of the strategy
     * @param string|null               $description        a description of the strategy
     * @param array<InputDefinitionDto> $inputs             an array of input definitions for the strategy
     * @param string|null               $timeframe          The enforced timeframe value if set (e.g. '1h'), otherwise null.
     * @param string[]                  $requiredMarketData An array of required secondary timeframe values (e.g., ['1h', '4h'])
     */
    public function __construct(
        public string $alias,
        public string $name,
        public ?string $description,
        public array $inputs,
        public ?string $timeframe,
        public array $requiredMarketData
    ) {
    }
}
