<?php

namespace Stochastix\Domain\Strategy\Service;

use Stochastix\Domain\Strategy\Attribute\AsStrategy;
use Stochastix\Domain\Strategy\Dto\StrategyDefinitionDto;
use Stochastix\Domain\Strategy\StrategyInterface;

interface StrategyRegistryInterface
{
    /**
     * @return array<StrategyDefinitionDto>
     */
    public function getStrategyDefinitions(): array;

    public function getStrategy(string $strategyAlias): ?StrategyInterface;

    public function getStrategyMetadata(string $strategyAlias): ?AsStrategy;
}
