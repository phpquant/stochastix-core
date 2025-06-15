<?php

namespace Stochastix\Domain\Order\Model;

use Stochastix\Domain\Common\Model\OhlcvSeries;
use Stochastix\Domain\Order\Dto\OrderSignal;

interface OrderManagerInterface
{
    /**
     * Queues an entry order signal for future execution.
     */
    public function queueEntry(OrderSignal $signal): void;

    /**
     * Queues an exit order signal for future execution.
     */
    public function queueExit(string $symbolToExit, OrderSignal $exitSignal): void;

    /**
     * Processes all queued signals using the data from the provided execution bar.
     */
    public function processSignalQueue(OhlcvSeries $executionBarData, \DateTimeImmutable $executionTime): void;

    public function getPortfolioManager(): PortfolioManagerInterface;

    public function checkPendingOrders(OhlcvSeries $bar, int $currentBarIndex): void;

    public function cancelPendingOrder(string $clientOrderId): void;
}
