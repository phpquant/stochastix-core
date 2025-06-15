<?php

namespace Stochastix\Domain\Strategy\Dto;

/**
 * Data Transfer Object for a strategy input definition.
 */
final readonly class InputDefinitionDto
{
    /**
     * @param string      $name         the name of the input property
     * @param string|null $description  a description of the input
     * @param string      $type         The simplified JSON-friendly type of the input (e.g., 'string', 'number', 'boolean', 'array').
     * @param mixed       $defaultValue the default value of the input, if any
     * @param float|null  $min          the minimum allowed value (for numeric inputs)
     * @param float|null  $max          the maximum allowed value (for numeric inputs)
     * @param array|null  $choices      a list of allowed choices for the input
     * @param int|null    $minChoices   the minimum number of items required in an array input
     * @param int|null    $maxChoices   the maximum number of items allowed in an array input
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public string $type,
        public mixed $defaultValue,
        public ?float $min,
        public ?float $max,
        public ?array $choices,
        public ?int $minChoices,
        public ?int $maxChoices,
    ) {
    }
}
