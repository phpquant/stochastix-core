<?php

namespace Stochastix\Tests\Domain\Order\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Common\Enum\OhlcvEnum;
use Stochastix\Domain\Common\Model\OhlcvSeries;
use Stochastix\Domain\Order\Dto\OrderSignal;
use Stochastix\Domain\Order\Dto\PositionDto;
use Stochastix\Domain\Order\Enum\OrderTypeEnum;
use Stochastix\Domain\Order\Model\OrderManager;
use Stochastix\Domain\Order\Model\PortfolioManagerInterface;
use Stochastix\Domain\Order\Service\OrderExecutorInterface;
use Stochastix\Tests\Support\TestDataFactoryTrait;

class OrderManagerTest extends TestCase
{
    use TestDataFactoryTrait;

    private OrderManager $orderManager;
    private PortfolioManagerInterface $portfolioManagerMock;
    private OrderExecutorInterface $orderExecutorMock;
    private LoggerInterface $loggerMock;
    private OhlcvSeries $dummyBars;
    private BacktestCursor $cursor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->portfolioManagerMock = $this->createMock(PortfolioManagerInterface::class);
        $this->orderExecutorMock = $this->createMock(OrderExecutorInterface::class);
        $this->cursor = new BacktestCursor();

        $this->orderManager = new OrderManager(
            $this->orderExecutorMock,
            $this->portfolioManagerMock,
            $this->cursor,
            $this->loggerMock
        );

        $marketData = [
            OhlcvEnum::Timestamp->value => [1735693200],
            OhlcvEnum::Open->value => [100.0],
            OhlcvEnum::High->value => [102.0],
            OhlcvEnum::Low->value => [99.0],
            OhlcvEnum::Close->value => [101.0],
            OhlcvEnum::Volume->value => [1000.0],
        ];
        $this->dummyBars = new OhlcvSeries($marketData, new BacktestCursor());
    }

    public function testQueueEntryDoesNotQueueIfNoCash(): void
    {
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('0.0');
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Market, '1.0');

        $this->loggerMock->expects($this->once())->method('warning')->with($this->stringContains('available cash is effectively zero'));

        $this->orderManager->queueEntry($signal);

        $ref = new \ReflectionClass($this->orderManager);
        $queue = $ref->getProperty('signalQueue');
        $this->assertEmpty($queue->getValue($this->orderManager));
    }

    public function testQueueEntryDoesNotQueueIfInPosition(): void
    {
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');

        $dummyPosition = new PositionDto(
            positionId: 'pos-dummy',
            symbol: 'BTC/USDT',
            direction: DirectionEnum::Long,
            entryPrice: '50000',
            quantity: '1.0',
            entryTime: new \DateTimeImmutable()
        );
        $this->portfolioManagerMock->method('getOpenPosition')->willReturn($dummyPosition);

        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Market, '1.0');

        $this->orderManager->queueEntry($signal);

        $ref = new \ReflectionClass($this->orderManager);
        $queue = $ref->getProperty('signalQueue');
        $this->assertEmpty($queue->getValue($this->orderManager));
    }

    public function testQueueEntrySuccessfullyAddsToQueue(): void
    {
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');
        $this->portfolioManagerMock->method('getOpenPosition')->willReturn(null);
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Market, '1.0');

        $this->orderManager->queueEntry($signal);

        $ref = new \ReflectionClass($this->orderManager);
        $queue = $ref->getProperty('signalQueue');
        $queueValue = $queue->getValue($this->orderManager);

        $this->assertCount(1, $queueValue);
        $this->assertSame($signal, $queueValue[0]);
    }

    public function testProcessSignalQueueExecutesEntry(): void
    {
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Market, '1.0');
        $executionResult = $this->createExecutionResult('BTC/USDT', DirectionEnum::Long, '100', '1', '0.1');

        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');
        $this->portfolioManagerMock->method('getOpenPosition')->willReturn(null);
        $this->orderManager->queueEntry($signal);

        $this->orderExecutorMock->expects($this->once())->method('execute')->with($signal)->willReturn($executionResult);
        $this->portfolioManagerMock->expects($this->once())->method('applyExecutionToOpenPosition')->with($executionResult)->willReturn(true);

        $this->orderManager->processSignalQueue($this->dummyBars, new \DateTimeImmutable());
    }

    public function testProcessSignalQueueExecutesExit(): void
    {
        $position = new PositionDto('pos1', 'BTC/USDT', DirectionEnum::Long, '90', '1.0', new \DateTimeImmutable());

        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Short, OrderTypeEnum::Market, '1.0'); // Exit signal
        $executionResult = $this->createExecutionResult('BTC/USDT', DirectionEnum::Short, '110', '1', '0.11');

        $this->portfolioManagerMock->method('getOpenPosition')->willReturn($position);
        $this->orderManager->queueExit('BTC/USDT', $signal);

        $this->orderExecutorMock->expects($this->once())->method('execute')->willReturn($executionResult);
        $this->portfolioManagerMock->expects($this->once())->method('applyExecutionToClosePosition')->with('pos1', $executionResult);

        $this->orderManager->processSignalQueue($this->dummyBars, new \DateTimeImmutable());
    }

    public function testLimitOrderIsPendingAndNotExecutedImmediately(): void
    {
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Limit, '1.0', '95.0', null, 'limit-buy-1');

        $this->orderManager->queueEntry($signal);

        $ref = new \ReflectionClass($this->orderManager);
        $pendingOrdersProp = $ref->getProperty('pendingOrders');
        $signalQueueProp = $ref->getProperty('signalQueue');

        $this->assertCount(1, $pendingOrdersProp->getValue($this->orderManager));
        $this->assertEmpty($signalQueueProp->getValue($this->orderManager));
    }

    public function testBuyLimitOrderTriggersWhenPriceIsMet(): void
    {
        // 1. Queue a pending buy limit order
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Limit, '1.0', '95.0', null, 'limit-buy-1');
        $this->orderManager->queueEntry($signal);

        // 2. Create a bar where the low price touches the limit price
        $marketData = [OhlcvEnum::Low->value => [94.9]];
        $triggerBar = new OhlcvSeries($marketData, new BacktestCursor());

        // 3. Check for triggers
        $this->orderManager->checkPendingOrders($triggerBar, 1);

        // 4. Assert that the signal was moved to the execution queue
        $ref = new \ReflectionClass($this->orderManager);
        $signalQueueProp = $ref->getProperty('signalQueue');
        $queue = $signalQueueProp->getValue($this->orderManager);

        $this->assertCount(1, $queue);
        $this->assertSame($signal, $queue[0]);
    }

    public function testSellStopOrderTriggersWhenPriceIsMet(): void
    {
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Short, OrderTypeEnum::Stop, '1.0', '98.0', null, 'stop-sell-1');
        $this->orderManager->queueEntry($signal);

        $marketData = [OhlcvEnum::Low->value => [97.0], OhlcvEnum::High->value => [99.0]]; // High must be above the stop price for short stop
        $triggerBar = new OhlcvSeries($marketData, new BacktestCursor());

        $this->orderManager->checkPendingOrders($triggerBar, 1);

        $ref = new \ReflectionClass($this->orderManager);
        $signalQueueProp = $ref->getProperty('signalQueue');
        $this->assertCount(1, $signalQueueProp->getValue($this->orderManager));
    }

    public function testPendingOrderIsCancelledCorrectly(): void
    {
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');
        $clientOrderId = 'cancel-me';
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Limit, '1.0', '95.0', null, $clientOrderId);
        $this->orderManager->queueEntry($signal);

        $ref = new \ReflectionClass($this->orderManager);
        $pendingOrdersProp = $ref->getProperty('pendingOrders');
        $this->assertCount(1, $pendingOrdersProp->getValue($this->orderManager));

        $this->orderManager->cancelPendingOrder($clientOrderId);

        $this->assertCount(0, $pendingOrdersProp->getValue($this->orderManager));
    }

    public function testPendingOrderExpiresAfterTimeInForceBars(): void
    {
        $this->portfolioManagerMock->method('getAvailableCash')->willReturn('10000');
        $this->cursor->currentIndex = 10;
        $signal = new OrderSignal('BTC/USDT', DirectionEnum::Long, OrderTypeEnum::Limit, '1.0', '95.0', 5, 'expire-me');
        $this->orderManager->queueEntry($signal);

        // Check before expiration
        $this->orderManager->checkPendingOrders($this->dummyBars, 14);
        $ref = new \ReflectionClass($this->orderManager);
        $pendingOrdersProp = $ref->getProperty('pendingOrders');
        $this->assertCount(1, $pendingOrdersProp->getValue($this->orderManager), 'Order should not expire yet.');

        // Check on the exact expiration bar
        $this->orderManager->checkPendingOrders($this->dummyBars, 15);
        $this->assertCount(0, $pendingOrdersProp->getValue($this->orderManager), 'Order should have expired.');
    }
}
