<?php

namespace Stochastix\Domain\Strategy\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class Input
{
    /**
     * @param class-string|null $arrayType  the expected class name for items in an array, typically a BackedEnum::class
     * @param int|null          $minChoices the minimum number of items required in an array input
     * @param int|null          $maxChoices the maximum number of items allowed in an array input
     */
    public function __construct(
        public ?string $description = null,
        public ?float $min = null,
        public ?float $max = null,
        public ?array $choices = null,
        public ?string $arrayType = null,
        public ?int $minChoices = null,
        public ?int $maxChoices = null,
    ) {
    }
}
