<?php

namespace Stochastix\Domain\Data\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback('validateDateRange')]
class DownloadRequestDto
{
    public function __construct(
        #[Assert\NotBlank]
        public string $exchangeId,
        #[Assert\NotBlank]
        public string $symbol,
        #[Assert\NotBlank]
        public string $timeframe,
        #[Assert\NotBlank]
        #[Assert\Date(message: 'Start date must be in Y-m-d format.')]
        public string $startDate,
        #[Assert\Date(message: 'End date must be in Y-m-d format.')]
        public string $endDate,
    ) {
    }

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
