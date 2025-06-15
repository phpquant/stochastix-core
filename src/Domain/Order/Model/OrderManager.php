<?php

namespace Stochastix\Domain\Order\Model;

use Ds\Map;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Model\OhlcvSeries;
use Stochastix\Domain\Order\Dto\OrderSignal;
use Stochastix\Domain\Order\Dto\PendingOrder;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;
use Stochastix\Domain\Order\Service\OrderExecutorInterface;

final class OrderManager implements OrderManagerInterface
{
    /** @var OrderSignal[] */
    private array $signalQueue = [];

    /** @var Map<string, PendingOrder> Keyed by clientOrderId */
    private Map $pendingOrders;

    public function __construct(
        private readonly OrderExecutorInterface $orderExecutor,
        private readonly PortfolioManagerInterface $portfolioManager,
        private readonly BacktestCursor $cursor,
        private readonly LoggerInterface $logger,
    ) {
        $this->pendingOrders = new Map();
    }

    public function queueEntry(OrderSignal $signal): void
    {
        if (bccomp($this->portfolioManager->getAvailableCash(), '0.00000001') < 0) {
            $this->logger->warning('Attempted to queue entry but available cash is effectively zero. Order rejected.');

            return;
        }

        if ($this->portfolioManager->getOpenPosition($signal->symbol) !== null) {
            $this->logger->debug('Attempted to queue entry but already in position for {symbol}', ['symbol' => $signal->symbol]);

            return;
        }

        // Market orders are queued for immediate execution on the next bar.
        // Limit and Stop orders are added to the pending book to be checked on each subsequent bar.
        if ($signal->orderType === OrderTypeEnum::Market) {
            $this->signalQueue[] = $signal;
            $this->logger->info('Market entry order for {symbol} queued.', ['symbol' => $signal->symbol]);
        } else {
            if ($signal->clientOrderId === null) {
                throw new \InvalidArgumentException('A clientOrderId is required for pending orders (Limit/Stop).');
            }
            // The Backtester should provide the currentBarIndex when creating the context or signal
            $creationIndex = $this->cursor->currentIndex;
            $this->pendingOrders->put($signal->clientOrderId, new PendingOrder($signal, $creationIndex));
            $this->logger->info('{type} entry order for {symbol} placed in pending book.', ['type' => $signal->orderType->value, 'symbol' => $signal->symbol]);
        }
    }

    public function cancelPendingOrder(string $clientOrderId): void
    {
        if ($this->pendingOrders->hasKey($clientOrderId)) {
            $this->pendingOrders->remove($clientOrderId);
            $this->logger->info('Pending order {id} cancelled by strategy.', ['id' => $clientOrderId]);
        }
    }

    public function checkPendingOrders(OhlcvSeries $bar, int $currentBarIndex): void
    {
        if ($this->pendingOrders->isEmpty()) {
            return;
        }

        $triggeredOrderIds = [];

        foreach ($this->pendingOrders as $clientOrderId => $pendingOrder) {
            $signal = $pendingOrder->signal;

            // Check for order expiration (Time in Force)
            if ($signal->timeInForceBars !== null && ($currentBarIndex - $pendingOrder->creationBarIndex) >= $signal->timeInForceBars) {
                $this->logger->info('Pending order {id} expired after {n} bars.', ['id' => $clientOrderId, 'n' => $signal->timeInForceBars]);
                $triggeredOrderIds[] = $clientOrderId; // Mark for removal
                continue;
            }

            // Check for trigger conditions
            $isTriggered = false;
            switch ($signal->orderType) {
                case OrderTypeEnum::Limit:
                    if ($signal->direction === DirectionEnum::Long && bccomp((string) $bar->low[0], $signal->price) <= 0) {
                        $isTriggered = true;
                    }
                    if ($signal->direction === DirectionEnum::Short && bccomp((string) $bar->high[0], $signal->price) >= 0) {
                        $isTriggered = true;
                    }
                    break;
                case OrderTypeEnum::Stop:
                    if ($signal->direction === DirectionEnum::Long && bccomp((string) $bar->high[0], $signal->price) >= 0) {
                        $isTriggered = true;
                    }
                    if ($signal->direction === DirectionEnum::Short && bccomp((string) $bar->low[0], $signal->price) <= 0) {
                        $isTriggered = true;
                    }
                    break;
            }

            if ($isTriggered) {
                $this->logger->info('Pending order {id} triggered.', ['id' => $clientOrderId]);
                $this->signalQueue[] = $signal; // Move to immediate execution queue
                $triggeredOrderIds[] = $clientOrderId; // Mark for removal from pending book
            }
        }

        // Remove triggered/expired orders from the pending book
        foreach ($triggeredOrderIds as $id) {
            $this->pendingOrders->remove($id);
        }
    }

    public function queueExit(string $symbolToExit, OrderSignal $exitSignal): void
    {
        $positionToClose = $this->portfolioManager->getOpenPosition($symbolToExit);

        if ($positionToClose === null) {
            $this->logger->warning('Attempted to queue exit for symbol {symbol} but no open position found.', ['symbol' => $symbolToExit]);

            return;
        }

        if (($positionToClose->direction === DirectionEnum::Long && $exitSignal->direction === DirectionEnum::Long)
            || ($positionToClose->direction === DirectionEnum::Short && $exitSignal->direction === DirectionEnum::Short)) {
            $this->logger->error('Exit signal direction mismatch with open position for {symbol}.', ['symbol' => $symbolToExit]);

            return;
        }

        $actualExitQuantity = bccomp($exitSignal->quantity, $positionToClose->quantity) > 0
            ? $positionToClose->quantity
            : $exitSignal->quantity;

        if (bccomp($actualExitQuantity, '0') <= 0) {
            $this->logger->warning('Exit quantity is zero or less for {symbol}.', ['symbol' => $symbolToExit]);

            return;
        }

        $adjustedExitSignal = new OrderSignal(
            symbol: $exitSignal->symbol,
            direction: $exitSignal->direction,
            orderType: $exitSignal->orderType,
            quantity: $actualExitQuantity,
            price: $exitSignal->price,
            clientOrderId: $exitSignal->clientOrderId,
            stopLossPrice: $exitSignal->stopLossPrice,
            takeProfitPrice: $exitSignal->takeProfitPrice
        );

        $this->signalQueue[] = $adjustedExitSignal;
        $this->logger->info('Exit order for {symbol} queued.', ['symbol' => $adjustedExitSignal->symbol]);
    }

    public function processSignalQueue(OhlcvSeries $executionBarData, \DateTimeImmutable $executionTime): void
    {
        $signalsToProcess = $this->signalQueue;
        $this->signalQueue = []; // Immediately clear queue to prevent re-processing

        foreach ($signalsToProcess as $signal) {
            $position = $this->portfolioManager->getOpenPosition($signal->symbol);
            $isExitSignal = ($position !== null)
                && (($position->direction === DirectionEnum::Long && $signal->direction === DirectionEnum::Short)
                    || ($position->direction === DirectionEnum::Short && $signal->direction === DirectionEnum::Long));

            $executionResult = $this->orderExecutor->execute($signal, $executionBarData, $executionTime);

            if (!$executionResult) {
                $this->logger->warning('Queued order execution failed for {symbol}', ['symbol' => $signal->symbol]);
                continue;
            }

            if ($isExitSignal) {
                $this->portfolioManager->applyExecutionToClosePosition($position->positionId, $executionResult);
                $this->logger->info('Queued exit executed: {symbol} {direction} @ {price} Qty: {qty}', [
                    'symbol' => $executionResult->symbol, 'direction' => $executionResult->direction->value,
                    'price' => $executionResult->filledPrice, 'qty' => $executionResult->filledQuantity,
                ]);
            } else {
                if ($this->portfolioManager->applyExecutionToOpenPosition($executionResult)) {
                    $this->logger->info('Queued entry executed: {symbol} {direction} @ {price} Qty: {qty}', [
                        'symbol' => $executionResult->symbol, 'direction' => $executionResult->direction->value,
                        'price' => $executionResult->filledPrice, 'qty' => $executionResult->filledQuantity,
                    ]);
                } else {
                    $this->logger->warning('Queued entry order executed but could not be applied (insufficient funds) for {symbol}.', ['symbol' => $signal->symbol]);
                }
            }
        }
    }

    public function getPortfolioManager(): PortfolioManagerInterface
    {
        return $this->portfolioManager;
    }
}
