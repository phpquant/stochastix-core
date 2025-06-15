<?php

namespace Stochastix\Domain\Order\Model;

use Ds\Map;
use Psr\Log\LoggerInterface;
use Stochastix\Domain\Common\Enum\DirectionEnum;
use Stochastix\Domain\Order\Dto\ExecutionResult;
use Stochastix\Domain\Order\Dto\PositionDto;

final class PortfolioManager implements PortfolioManagerInterface
{
    private string $initialCapital = '0.0';
    private string $availableCash = '0.0';
    private string $stakeCurrency = 'USDT';

    /** @var Map<string, PositionDto> Keyed by PositionDto->positionId */
    private Map $openPositions;
    private array $closedTradesLog = [];
    private int $closedTradeCounter = 0;

    public function __construct(private readonly LoggerInterface $logger)
    {
        $this->openPositions = new Map();
    }

    public function initialize(float|string $initialCapital, string $stakeCurrency): void
    {
        $this->initialCapital = (string) $initialCapital;
        $this->availableCash = (string) $initialCapital;
        $this->stakeCurrency = $stakeCurrency;
        $this->openPositions = new Map();
        $this->closedTradesLog = [];
        $this->closedTradeCounter = 0;
    }

    public function getInitialCapital(): string
    {
        return $this->initialCapital;
    }

    public function getAvailableCash(): string
    {
        return $this->availableCash;
    }

    public function getOpenPosition(string $symbol): ?PositionDto
    {
        foreach ($this->openPositions as $position) {
            if ($position->symbol === $symbol) {
                return $position;
            }
        }

        return null;
    }

    public function getAllOpenPositions(): array
    {
        return $this->openPositions->values()->toArray();
    }

    public function applyExecutionToOpenPosition(ExecutionResult $execution): bool
    {
        $costOrProceeds = bcmul($execution->filledQuantity, $execution->filledPrice);
        $totalDeduction = bcadd($costOrProceeds, $execution->commissionAmount);

        if ($execution->direction === DirectionEnum::Long) {
            if (bccomp($this->availableCash, $totalDeduction) < 0) {
                return false;
            }
        } else {
            if (bccomp($this->availableCash, $execution->commissionAmount) < 0) {
                return false;
            }
        }

        $position = new PositionDto(
            positionId: $execution->orderId,
            symbol: $execution->symbol,
            direction: $execution->direction,
            entryPrice: $execution->filledPrice,
            quantity: $execution->filledQuantity,
            entryTime: $execution->executedAt,
            entryCommissionAmount: $execution->commissionAmount,
            initialStopLossPrice: $execution->stopLossPrice,
            initialTakeProfitPrice: $execution->takeProfitPrice,
            enterTags: $execution->enterTags
        );

        $this->openPositions->put($position->positionId, $position);

        if ($execution->direction === DirectionEnum::Long) {
            $this->availableCash = bcsub($this->availableCash, $costOrProceeds);
        } else {
            $this->availableCash = bcadd($this->availableCash, $costOrProceeds);
        }
        $this->availableCash = bcsub($this->availableCash, $execution->commissionAmount);

        if (bccomp($this->availableCash, '0') < 0) {
            $this->logger->warning('Cash went negative after opening position {id} (unexpected), setting to 0.', ['id' => $position->positionId]);
            $this->availableCash = '0.0';
        }

        return true;
    }

    public function applyExecutionToClosePosition(string $positionIdToClose, ExecutionResult $closingExecution): void
    {
        if (!$this->openPositions->hasKey($positionIdToClose)) {
            return;
        }
        /** @var PositionDto $positionToClose */
        $positionToClose = $this->openPositions->get($positionIdToClose);

        $closedQuantity = $closingExecution->filledQuantity;

        // Determine if this is a partial or full close
        $quantityComparison = bccomp($closedQuantity, $positionToClose->quantity);

        // Calculate PnL and cash adjustments based on the quantity being closed
        $entryValueForClosedPortion = bcmul($closedQuantity, $positionToClose->entryPrice);
        $exitValueForClosedPortion = bcmul($closedQuantity, $closingExecution->filledPrice);

        if ($positionToClose->direction === DirectionEnum::Long) {
            $grossPnl = bcsub($exitValueForClosedPortion, $entryValueForClosedPortion);
            $this->availableCash = bcadd($this->availableCash, $exitValueForClosedPortion);
        } else { // Short position
            $grossPnl = bcsub($entryValueForClosedPortion, $exitValueForClosedPortion);
            $this->availableCash = bcsub($this->availableCash, $exitValueForClosedPortion);
        }

        $this->availableCash = bcsub($this->availableCash, $closingExecution->commissionAmount);

        // The total commission for this closing trade is just the exit commission.
        // The entry commission for the closed portion needs to be calculated pro-rata.
        $entryCommissionForClosedPortion = bcmul(
            $positionToClose->entryCommissionAmount,
            bcdiv($closedQuantity, $positionToClose->quantity)
        );
        $totalCommission = bcadd($entryCommissionForClosedPortion, $closingExecution->commissionAmount);
        $netPnl = bcsub($grossPnl, $totalCommission);

        // Handle negative cash scenario
        if (bccomp($this->availableCash, '0') < 0) {
            $overdraft = bcsub('0', $this->availableCash);
            $netPnl = bcadd($netPnl, $overdraft);
            $this->availableCash = '0.0';
        }

        ++$this->closedTradeCounter;

        $this->closedTradesLog[] = [
            'tradeNumber' => $this->closedTradeCounter,
            'positionId' => $positionToClose->positionId,
            'symbol' => $positionToClose->symbol,
            'direction' => $positionToClose->direction->value,
            'entryPrice' => $positionToClose->entryPrice,
            'exitPrice' => $closingExecution->filledPrice,
            'quantity' => $closedQuantity,
            'entryTime' => $positionToClose->entryTime->format('Y-m-d H:i:s'),
            'exitTime' => $closingExecution->executedAt->format('Y-m-d H:i:s'),
            'pnl' => $netPnl,
            'entryCommission' => $entryCommissionForClosedPortion,
            'exitCommission' => $closingExecution->commissionAmount,
            'enterTags' => $positionToClose->enterTags,
            'exitTags' => $closingExecution->exitTags,
        ];

        // If it was a full close, remove the position. If partial, update it.
        if ($quantityComparison >= 0) { // Full or over-close
            $this->openPositions->remove($positionIdToClose);
        } else { // Partial close
            $newQuantity = bcsub($positionToClose->quantity, $closedQuantity);
            $remainingEntryCommission = bcsub($positionToClose->entryCommissionAmount, $entryCommissionForClosedPortion);

            $updatedPosition = new PositionDto(
                $positionToClose->positionId,
                $positionToClose->symbol,
                $positionToClose->direction,
                $positionToClose->entryPrice,
                $newQuantity,
                $positionToClose->entryTime,
                $remainingEntryCommission,
                $positionToClose->initialStopLossPrice,
                $positionToClose->initialTakeProfitPrice,
                $positionToClose->enterTags
            );
            $this->openPositions->put($positionToClose->positionId, $updatedPosition);
        }
    }

    public function getClosedTrades(): array
    {
        return $this->closedTradesLog;
    }
}
