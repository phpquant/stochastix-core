<?php

namespace Stochastix\Domain\Backtesting\Event;

namespace Stochastix\Domain\Backtesting\Event;

use Symfony\Contracts\EventDispatcher\Event;

final class BacktestPhaseEvent extends Event
{
    public function __construct(
        public readonly string $runId,
        public readonly string $phase,
        public readonly string $eventType,
    ) {
    }
}
