<?php

namespace Stochastix\Domain\Backtesting\Service\Metric;

use Stochastix\Domain\Common\Enum\DirectionEnum;

final class Equity extends AbstractSeriesMetric
{
    public function calculate(array $backtestResults): void
    {
        $config = $backtestResults['config'];
        $closedTrades = $backtestResults['closedTrades'] ?? [];
        $marketData = $backtestResults['marketData'] ?? [];

        if (empty($marketData)) {
            $this->values = [];

            return;
        }

        $realizedPnl = '0.0';
        $equityCurveValues = [];
        $openPosition = null;

        $tradesByEntryTime = [];
        $tradesByExitTime = [];
        foreach ($closedTrades as $trade) {
            $tradesByEntryTime[strtotime($trade['entryTime'])] = $trade;
            $tradesByExitTime[strtotime($trade['exitTime'])] = $trade;
        }

        foreach ($marketData as $bar) {
            $currentTimestamp = $bar['timestamp'];
            $currentClose = (string) $bar['close'];

            // Start with the baseline equity: initial capital + any profit/loss that has been realized so far.
            $currentEquity = bcadd($config->initialCapital, $realizedPnl);

            // If a position is currently open, calculate its unrealized PnL and add it to the baseline equity.
            if ($openPosition !== null) {
                $unrealizedPnl = bcmul(bcsub($currentClose, (string) $openPosition['entryPrice']), (string) $openPosition['quantity']);
                if ($openPosition['direction'] === DirectionEnum::Short->value) {
                    $unrealizedPnl = bcmul($unrealizedPnl, '-1');
                }
                $currentEquity = bcadd($currentEquity, $unrealizedPnl);
            }

            $equityCurveValues[] = (float) $currentEquity;

            // After calculating this bar's equity, process any events that occurred at this timestamp.

            // If a trade closes, add its PnL to the realized total and clear the open position.
            if (isset($tradesByExitTime[$currentTimestamp])) {
                $exitingTrade = $tradesByExitTime[$currentTimestamp];
                $realizedPnl = bcadd($realizedPnl, (string) $exitingTrade['pnl']);
                $openPosition = null;
            }

            // If a trade opens, set it as the new open position for the next iteration.
            if (isset($tradesByEntryTime[$currentTimestamp])) {
                $openPosition = $tradesByEntryTime[$currentTimestamp];
            }
        }

        $this->values = $equityCurveValues;
    }
}
