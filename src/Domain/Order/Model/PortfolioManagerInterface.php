<?php

namespace Stochastix\Domain\Order\Model;

use Stochastix\Domain\Order\Dto\ExecutionResult;
use Stochastix\Domain\Order\Dto\PositionDto;

interface PortfolioManagerInterface
{
    public function initialize(float|string $initialCapital, string $stakeCurrency): void;

    public function getOpenPosition(string $symbol): ?PositionDto;

    public function getAllOpenPositions(): array;

    public function applyExecutionToOpenPosition(ExecutionResult $execution): bool;

    public function applyExecutionToClosePosition(string $positionIdToClose, ExecutionResult $closingExecution): void;

    public function getClosedTrades(): array;

    public function getInitialCapital(): string;

    public function getAvailableCash(): string;
}
