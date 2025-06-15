<?php

namespace Stochastix\Domain\Backtesting\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * DTO for launching a backtest via the API.
 * Uses a Callback constraint to validate the date range only when both dates are provided.
 */
#[Assert\Callback('validateDateRange')]
class LaunchBacktestRequestDto
{
    /**
     * @param string[]                  $symbols
     * @param array<string, mixed>      $inputs
     * @param array<string, mixed>|null $commissionConfig
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $strategyAlias,
        #[Assert\NotBlank]
        #[Assert\All([new Assert\NotBlank(), new Assert\Type('string')])]
        public array $symbols,
        #[Assert\NotBlank]
        public string $timeframe,
        #[Assert\Date(message: 'Start date must be in Y-m-d format.')]
        public ?string $startDate,
        #[Assert\Date(message: 'End date must be in Y-m-d format.')]
        public ?string $endDate,
        #[Assert\NotBlank]
        #[Assert\Type('string')]
        #[Assert\PositiveOrZero(message: 'Initial capital must be a non-negative number.')]
        #[Assert\Regex(pattern: "/^\d+(\.\d+)?$/", message: 'Initial capital must be a valid numeric string.')]
        public string $initialCapital,
        public ?string $dataSourceExchangeId = null,
        #[Assert\Type('array')]
        public array $inputs = [],
        #[Assert\NotBlank]
        public string $stakeCurrency = 'USDT',
        #[Assert\Type('string')]
        public ?string $stakeAmountConfig = null,
        #[Assert\Collection(
            fields: [
                'type' => new Assert\Required([
                    new Assert\NotBlank(),
                    new Assert\Choice(['percentage', 'fixed_per_trade', 'fixed_per_unit']),
                ]),
                'rate' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\PositiveOrZero(),
                    new Assert\Regex(pattern: "/^\d+(\.\d+)?$/", message: 'Commission rate must be a valid numeric string.'),
                ]),
                'amount' => new Assert\Optional([
                    new Assert\Type('string'),
                    new Assert\PositiveOrZero(),
                    new Assert\Regex(pattern: "/^\d+(\.\d+)?$/", message: 'Commission amount must be a valid numeric string.'),
                ]),
                'asset' => new Assert\Optional([new Assert\Type('string')]),
            ],
            allowExtraFields: false,
            allowMissingFields: true,
        )]
        public ?array $commissionConfig = null,
    ) {
    }

    /**
     * This callback method provides conditional validation for the date range.
     * It only triggers if both startDate and endDate are non-null.
     */
    public function validateDateRange(ExecutionContextInterface $context): void
    {
        if ($this->startDate !== null && $this->endDate !== null) {
            if ($this->endDate < $this->startDate) {
                $context->buildViolation('End date must be after or the same as start date.')
                    ->atPath('endDate')
                    ->addViolation();
            }
        }
    }
}
