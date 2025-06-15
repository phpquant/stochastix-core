<?php

namespace Stochastix\Tests\Domain\Order\Model;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Order\Model\PortfolioManager;
use Stochastix\Tests\Support\BcMathAssertionsTrait;
use Stochastix\Tests\Support\TestDataFactoryTrait;

class PortfolioManagerTest extends TestCase
{
    use BcMathAssertionsTrait;
    use TestDataFactoryTrait;

    private PortfolioManager $portfolioManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->portfolioManager = new PortfolioManager(new NullLogger());
    }

    public function testInitialization(): void
    {
        $this->portfolioManager->initialize('10000.00', 'USDT');

        self::assertBcEquals('10000.00', $this->portfolioManager->getInitialCapital());
        self::assertBcEquals('10000.00', $this->portfolioManager->getAvailableCash());
        self::assertEmpty($this->portfolioManager->getAllOpenPositions());
        self::assertEmpty($this->portfolioManager->getClosedTrades());
    }

    public function testOpenLongPositionSufficientFunds(): void
    {
        $this->portfolioManager->initialize('1000.00', 'USDT');
        $execution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Long,
            '500.00',
            '1.5',
            '5.00'
        );

        $cost = bcmul('500.00', '1.5', 8);
        $totalDeduction = bcadd($cost, '5.00', 8);
        $expectedCash = bcsub('1000.00', $totalDeduction, 8);

        $result = $this->portfolioManager->applyExecutionToOpenPosition($execution);

        self::assertTrue($result);
        self::assertBcEquals($expectedCash, $this->portfolioManager->getAvailableCash());
        self::assertCount(1, $this->portfolioManager->getAllOpenPositions());
        $position = $this->portfolioManager->getOpenPosition('BTC/USDT');
        self::assertNotNull($position);
        self::assertBcEquals('500.00', $position->entryPrice);
    }

    public function testOpenLongPositionInsufficientFunds(): void
    {
        $this->portfolioManager->initialize('100.00', 'USDT');
        $execution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Long,
            '500.00',
            '1.5',
            '5.00'
        );

        $result = $this->portfolioManager->applyExecutionToOpenPosition($execution);

        self::assertFalse($result);
        self::assertBcEquals('100.00', $this->portfolioManager->getAvailableCash());
        self::assertCount(0, $this->portfolioManager->getAllOpenPositions());
    }

    public function testCloseLongPositionProfit(): void
    {
        $this->portfolioManager->initialize('1000.00', 'USDT');
        $openExecution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Long,
            '500.00',
            '1.0',
            '2.00',
            'USDT',
            'pos1'
        );
        $this->portfolioManager->applyExecutionToOpenPosition($openExecution);
        // Cash = 1000 - (500*1) - 2 = 498.00

        $closeExecution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Short, // To close a long
            '600.00',
            '1.0',
            '3.00'
        );
        $this->portfolioManager->applyExecutionToClosePosition('pos1', $closeExecution);
        // Cash = 498 + (600*1) - 3 = 1095.00

        self::assertBcEquals('1095.00', $this->portfolioManager->getAvailableCash());
        $closedTrades = $this->portfolioManager->getClosedTrades();
        self::assertCount(1, $closedTrades);
        self::assertBcEquals('95.00', $closedTrades[0]['pnl']); // PNL = (600 - 500) - (2+3) = 95
    }

    public function testClosePositionCapsAtZeroAndAdjustsPnl(): void
    {
        $this->portfolioManager->initialize('10.00', 'USDT');

        $openExecution = $this->createExecutionResult(
            'ETH/USDT',
            DirectionEnum::Long,
            '8.00',
            '1.0',
            '1.00',
            'USDT', // commissionAsset
            'pos1'  // orderId
        );
        $this->portfolioManager->applyExecutionToOpenPosition($openExecution);
        self::assertBcEquals('1.00', $this->portfolioManager->getAvailableCash(), 8, 'Cash should be 1.00 after open');

        $closeExecution = $this->createExecutionResult(
            'ETH/USDT',
            DirectionEnum::Short,
            '0.10',
            '1.0',
            '5.00',
            'USDT', // commissionAsset
            'ord-close' // orderId
        );

        $this->portfolioManager->applyExecutionToClosePosition('pos1', $closeExecution);

        self::assertBcEquals('0.00', $this->portfolioManager->getAvailableCash(), 8, 'Cash should be capped at 0.00');

        $closedTrades = $this->portfolioManager->getClosedTrades();
        self::assertCount(1, $closedTrades);
        self::assertBcEquals('-10.00', $closedTrades[0]['pnl'], 8, 'PNL should be adjusted to -10.00');
    }

    public function testOpenShortPosition(): void
    {
        $this->portfolioManager->initialize('1000.00', 'USDT');
        $execution = $this->createExecutionResult(
            'ETH/USDT',
            DirectionEnum::Short,
            '2000.00',
            '1.0',
            '5.00'
        );

        // On short, proceeds are added, commission is subtracted.
        // Cash = 1000 + (2000 * 1) - 5 = 2995.00
        $expectedCash = '2995.00';

        $result = $this->portfolioManager->applyExecutionToOpenPosition($execution);

        self::assertTrue($result);
        self::assertBcEquals($expectedCash, $this->portfolioManager->getAvailableCash());
        self::assertCount(1, $this->portfolioManager->getAllOpenPositions());
        $position = $this->portfolioManager->getOpenPosition('ETH/USDT');
        self::assertNotNull($position);
        self::assertSame(DirectionEnum::Short, $position->direction);
        self::assertBcEquals('2000.00', $position->entryPrice);
    }

    public function testCloseShortPositionProfit(): void
    {
        $this->portfolioManager->initialize('1000.00', 'USDT');
        // 1. Open short position
        $openExecution = $this->createExecutionResult(
            'ETH/USDT',
            DirectionEnum::Short,
            '2000.00',
            '1.0',
            '5.00',
            'USDT',
            'pos-short-1'
        );
        $this->portfolioManager->applyExecutionToOpenPosition($openExecution);
        // Cash after open = 1000 + 2000 - 5 = 2995.00

        // 2. Close short position for a profit (buy back cheaper)
        $closeExecution = $this->createExecutionResult(
            'ETH/USDT',
            DirectionEnum::Long, // To close a short
            '1900.00',
            '1.0',
            '4.00'
        );
        $this->portfolioManager->applyExecutionToClosePosition('pos-short-1', $closeExecution);
        // Cash after close = 2995 - (1900*1) - 4 = 1091.00

        self::assertBcEquals('1091.00', $this->portfolioManager->getAvailableCash());
        $closedTrades = $this->portfolioManager->getClosedTrades();
        self::assertCount(1, $closedTrades);
        // PNL = (2000 - 1900) - (5 + 4) = 100 - 9 = 91.00
        self::assertBcEquals('91.00', $closedTrades[0]['pnl']);
        self::assertEmpty($this->portfolioManager->getAllOpenPositions(), 'Position should be closed');
    }

    public function testCloseShortPositionLoss(): void
    {
        $this->portfolioManager->initialize('1000.00', 'USDT');
        // 1. Open short position
        $openExecution = $this->createExecutionResult(
            'ETH/USDT',
            DirectionEnum::Short,
            '2000.00',
            '1.0',
            '5.00',
            'USDT',
            'pos-short-2'
        );
        $this->portfolioManager->applyExecutionToOpenPosition($openExecution);
        // Cash after open = 1000 + 2000 - 5 = 2995.00

        // 2. Close short position for a loss (buy back more expensive)
        $closeExecution = $this->createExecutionResult(
            'ETH/USDT',
            DirectionEnum::Long, // To close a short
            '2100.00',
            '1.0',
            '6.00'
        );
        $this->portfolioManager->applyExecutionToClosePosition('pos-short-2', $closeExecution);
        // Cash after close = 2995 - (2100*1) - 6 = 889.00

        self::assertBcEquals('889.00', $this->portfolioManager->getAvailableCash());
        $closedTrades = $this->portfolioManager->getClosedTrades();
        self::assertCount(1, $closedTrades);
        // PNL = (2000 - 2100) - (5 + 6) = -100 - 11 = -111.00
        self::assertBcEquals('-111.00', $closedTrades[0]['pnl']);
        self::assertEmpty($this->portfolioManager->getAllOpenPositions(), 'Position should be closed');
    }

    public function testPositionLifecycleLogsTagsCorrectly(): void
    {
        $this->portfolioManager->initialize('1000', 'USDT');

        // 1. Open position with enter tags
        $openExecution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Long,
            '500',
            '1',
            '2',
            'USDT',
            'pos1',
            null,
            null,
            ['ema_cross', 'volume_spike']
        );
        $this->portfolioManager->applyExecutionToOpenPosition($openExecution);

        $position = $this->portfolioManager->getOpenPosition('BTC/USDT');
        $this->assertNotNull($position);
        $this->assertEquals(['ema_cross', 'volume_spike'], $position->enterTags);

        // 2. Close position with exit tags
        $closeExecution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Short,
            '600',
            '1',
            '3',
            'USDT',
            'ord-close',
            null,
            null,
            null,
            ['exit_signal']
        );
        $this->portfolioManager->applyExecutionToClosePosition('pos1', $closeExecution);

        $closedTrades = $this->portfolioManager->getClosedTrades();
        $this->assertCount(1, $closedTrades);
        $tradeLog = $closedTrades[0];

        $this->assertArrayHasKey('enterTags', $tradeLog);
        $this->assertArrayHasKey('exitTags', $tradeLog);

        $this->assertEquals(['ema_cross', 'volume_spike'], $tradeLog['enterTags']);
        $this->assertEquals(['exit_signal'], $tradeLog['exitTags']);
    }

    public function testPartialCloseLongPosition(): void
    {
        $this->portfolioManager->initialize('1000.00', 'USDT');

        // 1. Open a position of quantity 2.0
        $openExecution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Long,
            '500.00',
            '2.0',
            '4.00',
            'USDT',
            'pos1'
        );
        // Cost = (500 * 2) + 4 = 1004. Initial cash is 1000, so this should fail. Let's adjust initial cash.
        $this->portfolioManager->initialize('2000.00', 'USDT');
        $this->portfolioManager->applyExecutionToOpenPosition($openExecution);
        // Cash after open = 2000 - 1004 = 996.00

        self::assertBcEquals('996.00', $this->portfolioManager->getAvailableCash());
        $this->assertCount(1, $this->portfolioManager->getAllOpenPositions());

        // 2. Partially close 1.2 of the 2.0 quantity
        $partialCloseExecution = $this->createExecutionResult(
            'BTC/USDT',
            DirectionEnum::Short,
            '600.00',
            '1.2',
            '3.00'
        );
        $this->portfolioManager->applyExecutionToClosePosition('pos1', $partialCloseExecution);
        // Proceeds = 600 * 1.2 = 720. Cash = 996 + 720 - 3 = 1713.00

        // 3. Assertions
        $this->assertCount(1, $this->portfolioManager->getAllOpenPositions(), 'Position should still be open');

        $openPosition = $this->portfolioManager->getOpenPosition('BTC/USDT');
        $this->assertNotNull($openPosition);
        self::assertBcEquals('0.8', $openPosition->quantity, 8, 'Remaining quantity should be 0.8');

        $this->assertCount(1, $this->portfolioManager->getClosedTrades(), 'One partial trade should be logged');
        $closedTrade = $this->portfolioManager->getClosedTrades()[0];
        self::assertBcEquals('1.2', $closedTrade['quantity'], 8, 'Logged quantity should be 1.2');

        // PNL = (exitPrice - entryPrice) * partialQty - commissions
        // Entry commission for partial = 4.00 * (1.2 / 2.0) = 2.40
        // Total commission = 2.40 + 3.00 = 5.40
        // PNL = (600 - 500) * 1.2 - 5.40 = 100 * 1.2 - 5.40 = 120 - 5.40 = 114.60
        self::assertBcEquals('114.60', $closedTrade['pnl'], 8, 'PNL for partial close is incorrect');

        self::assertBcEquals('1713.00', $this->portfolioManager->getAvailableCash(), 8, 'Final cash is incorrect');
    }
}
