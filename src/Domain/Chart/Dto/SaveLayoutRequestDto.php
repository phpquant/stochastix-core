<?php

namespace Stochastix\Domain\Chart\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Data Transfer Object used for creating or updating a ChartLayout.
 * This object is mapped directly from the API request payload.
 */
final readonly class SaveLayoutRequestDto
{
    /**
     * @param string $name The user-defined name for the layout.
     * @param string $symbol The symbol associated with the layout (e.g., "BTC/USDT").
     * @param string $timeframe The timeframe for the layout (e.g., "1h", "4h").
     * @param IndicatorRequest[] $indicators An array of indicator configurations.
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 255)]
        public string $name,
        #[Assert\NotBlank]
        public string $symbol,
        #[Assert\NotBlank]
        public string $timeframe,
        #[Assert\Valid]
        #[Assert\All(new Assert\Type(type: IndicatorRequest::class))]
        public array $indicators,
    ) {
    }
}

