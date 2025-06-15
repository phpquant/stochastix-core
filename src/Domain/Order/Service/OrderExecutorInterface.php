<?php

namespace Stochastix\Domain\Order\Service;

use Stochastix\Domain\Common\Model\OhlcvSeries;
use Stochastix\Domain\Order\Dto\ExecutionResult;
use Stochastix\Domain\Order\Dto\OrderSignal;

interface OrderExecutorInterface
{
    public function execute(
        OrderSignal $signal,
        OhlcvSeries $currentBarData,
        \DateTimeImmutable $executionTime
    ): ?ExecutionResult;
}
