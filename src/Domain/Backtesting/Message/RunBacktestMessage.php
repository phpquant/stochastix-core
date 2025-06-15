<?php

namespace Stochastix\Domain\Backtesting\Message;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration; // Ensure correct namespace

/**
 * Message to command the execution of a backtest.
 */
final readonly class RunBacktestMessage
{
    public function __construct(
        public string $backtestRunId,
        public BacktestConfiguration $configuration
    ) {
    }
}
