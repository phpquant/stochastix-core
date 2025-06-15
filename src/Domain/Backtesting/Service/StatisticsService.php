<?php

namespace Stochastix\Domain\Backtesting\Service;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Service\Metric\SummaryMetricInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class StatisticsService implements StatisticsServiceInterface
{
    private const int DISPLAY_SCALE = 4;

    /** @var SummaryMetricInterface[] */
    private array $metrics;

    public function __construct(
        #[AutowireIterator(SummaryMetricInterface::class)]
        iterable $metrics
    ) {
        $this->metrics = iterator_to_array($metrics);
    }

    public function calculate(array $results): array
    {
        /** @var BacktestConfiguration $config */
        $config = $results['config'];
        $closedTrades = $results['closedTrades'] ?? [];
        $initialCapital = $config->initialCapital;

        // 1. Establish a baseline summary with defaults for a no-trade scenario.
        $summary = [
            'backtestingFrom' => ($config->startDate ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'backtestingTo' => ($config->endDate ?? new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'maxOpenTrades' => 0,
            'totalTrades' => 0,
            'dailyAvgTrades' => 0.0,
            'startingBalance' => (float) $initialCapital,
            'finalBalance' => (float) ($results['finalCapital'] ?? $initialCapital),
            'balanceCurrency' => $config->stakeCurrency,
            'absProfit' => (float) bcsub($results['finalCapital'] ?? $initialCapital, $initialCapital, self::DISPLAY_SCALE),
            'totalProfitPercentage' => bccomp($initialCapital, '0', 2) > 0 ? (float) bcmul(bcdiv(bcsub($results['finalCapital'] ?? $initialCapital, $initialCapital), $initialCapital), '100', self::DISPLAY_SCALE) : 0.0,
            'profitFactor' => null,
            'expectancy' => 0.0,
            'expectancyRatio' => null,
            'avgDurationWinnersMin' => 0,
            'avgDurationLosersMin' => 0,
            'maxConsecutiveWins' => 0,
            'maxConsecutiveLosses' => 0,
        ];

        // 2. Always calculate all summary metrics. The metric services are responsible for handling the no-trade case.
        foreach ($this->metrics as $metric) {
            $metric->calculate($results, $config);
            $summary = array_merge($summary, $metric->getMetrics());
        }

        // 3. If trades exist, overwrite the defaults with calculated, trade-dependent stats.
        if (!empty($closedTrades)) {
            $summary['maxOpenTrades'] = 1; // Assuming no parallel trades for now
            $summary['dailyAvgTrades'] = (float) bcdiv((string) count($closedTrades), (string) max(1, ($config->endDate ?? new \DateTimeImmutable())->diff($config->startDate ?? new \DateTimeImmutable())->days), self::DISPLAY_SCALE);

            $this->overwriteWithTradeStats($summary, $closedTrades);
        }

        return [
            'pairStats' => empty($closedTrades) ? [] : array_values($this->calculateGroupedStats($closedTrades, 'symbol')),
            'enterTagStats' => empty($closedTrades) ? [] : array_values($this->calculateGroupedStats($closedTrades, 'enter_tags')),
            'exitTagStats' => empty($closedTrades) ? [] : array_values($this->calculateGroupedStats($closedTrades, 'exit_tags')),
            'summaryMetrics' => $summary,
        ];
    }

    /**
     * Overwrites the summary array with metrics that can only be calculated if trades exist.
     */
    private function overwriteWithTradeStats(array &$summary, array $closedTrades): void
    {
        $allPnls = array_map('strval', array_column($closedTrades, 'pnl'));
        $winningTrades = array_filter($allPnls, static fn ($pnl) => bccomp($pnl, '0') > 0);
        $losingTrades = array_filter($allPnls, static fn ($pnl) => bccomp($pnl, '0') < 0);
        $winsCount = count($winningTrades);
        $lossesCount = count($losingTrades);

        $grossProfit = array_reduce($winningTrades, static fn ($sum, $pnl) => bcadd($sum, (string) $pnl), '0');
        $absGrossLoss = bcmul(array_reduce($losingTrades, static fn ($sum, $pnl) => bcadd($sum, (string) $pnl), '0'), '-1');

        $summary['profitFactor'] = bccomp($absGrossLoss, '0') > 0 ? (float) bcdiv($grossProfit, $absGrossLoss, self::DISPLAY_SCALE) : null;

        $this->addConsecutiveStats($summary, $allPnls);
        $this->addDurationStats($summary, $closedTrades);
        $this->addExpectancyStats($summary, $grossProfit, $absGrossLoss, $winsCount, $lossesCount);
    }

    private function addExpectancyStats(array &$summary, string $grossProfit, string $absGrossLoss, int $winsCount, int $lossesCount): void
    {
        $totalTrades = $winsCount + $lossesCount;
        if ($totalTrades === 0) {
            $summary['expectancy'] = 0.0;
            $summary['expectancyRatio'] = null;

            return;
        }

        $winRate = bcdiv((string) $winsCount, (string) $totalTrades);
        $lossRate = bcdiv((string) $lossesCount, (string) $totalTrades);

        $avgWin = $winsCount > 0 ? bcdiv($grossProfit, (string) $winsCount) : '0';
        $avgLoss = $lossesCount > 0 ? bcdiv($absGrossLoss, (string) $lossesCount) : '0';

        $expectancy = bcsub(bcmul($winRate, $avgWin), bcmul($lossRate, $avgLoss), self::DISPLAY_SCALE);
        $expectancyRatio = bccomp($avgLoss, '0') > 0 ? bcdiv(bcmul($winRate, bcdiv($avgWin, $avgLoss)), $lossRate, self::DISPLAY_SCALE) : null;

        $summary['expectancy'] = (float) $expectancy;
        $summary['expectancyRatio'] = $expectancyRatio !== null ? (float) $expectancyRatio : null;
    }

    private function addDurationStats(array &$summary, array $closedTrades): void
    {
        $winnerDurations = [];
        $loserDurations = [];
        foreach ($closedTrades as $trade) {
            $duration = (new \DateTimeImmutable($trade['exitTime']))->getTimestamp() - (new \DateTimeImmutable($trade['entryTime']))->getTimestamp();
            if (bccomp((string) $trade['pnl'], '0') > 0) {
                $winnerDurations[] = $duration;
            } else {
                $loserDurations[] = $duration;
            }
        }
        $summary['avgDurationWinnersMin'] = !empty($winnerDurations) ? (int) round((array_sum($winnerDurations) / count($winnerDurations)) / 60) : 0;
        $summary['avgDurationLosersMin'] = !empty($loserDurations) ? (int) round((array_sum($loserDurations) / count($loserDurations)) / 60) : 0;
    }

    private function addConsecutiveStats(array &$summary, array $pnlList): void
    {
        $maxWins = 0;
        $maxLosses = 0;
        $currentWins = 0;
        $currentLosses = 0;
        foreach ($pnlList as $pnl) {
            if (bccomp((string) $pnl, '0') > 0) {
                ++$currentWins;
                $currentLosses = 0;
            } else {
                ++$currentLosses;
                $currentWins = 0;
            }
            $maxWins = max($maxWins, $currentWins);
            $maxLosses = max($maxLosses, $currentLosses);
        }
        $summary['maxConsecutiveWins'] = $maxWins;
        $summary['maxConsecutiveLosses'] = $maxLosses;
    }

    private function calculateGroupedStats(array $trades, string $groupKey): array
    {
        $stats = [];
        foreach ($trades as $trade) {
            $keys = match ($groupKey) {
                'symbol' => [$trade['symbol']],
                default => $trade[$groupKey] ?? [],
            };
            foreach ($keys as $key) {
                $stats[$key] ??= $this->getInitialStatGroup($key);
                $stats[$key] = $this->accumulateStats($stats[$key], $trade);
            }
        }

        return array_map([$this, 'finalizeStats'], $stats);
    }

    private function getInitialStatGroup(string $label): array
    {
        return [
            'label' => $label, 'trades' => 0, 'wins' => 0, 'losses' => 0, 'draws' => 0,
            'totalProfit' => '0.0', 'totalDurationSeconds' => 0, 'totalEntryValue' => '0.0',
        ];
    }

    private function accumulateStats(array $group, array $trade): array
    {
        ++$group['trades'];
        $pnl = (string) $trade['pnl'];

        if (bccomp($pnl, '0') > 0) {
            ++$group['wins'];
        } elseif (bccomp($pnl, '0') < 0) {
            ++$group['losses'];
        } else {
            ++$group['draws'];
        }

        $group['totalProfit'] = bcadd($group['totalProfit'], $pnl);
        $group['totalDurationSeconds'] += (new \DateTimeImmutable($trade['exitTime']))->getTimestamp() - (new \DateTimeImmutable($trade['entryTime']))->getTimestamp();
        $group['totalEntryValue'] = bcadd($group['totalEntryValue'], bcmul((string) $trade['quantity'], (string) $trade['entryPrice']));

        return $group;
    }

    private function finalizeStats(array $group): array
    {
        $totalProfitPercentage = 0.0;
        if (bccomp($group['totalEntryValue'], '0') > 0) {
            $totalProfitPercentage = (float) bcmul(bcdiv($group['totalProfit'], $group['totalEntryValue']), '100', self::DISPLAY_SCALE);
        }

        return [
            'label' => $group['label'],
            'trades' => $group['trades'],
            'averageProfitPercentage' => $group['trades'] > 0 ? (float) bcdiv((string) $totalProfitPercentage, (string) $group['trades'], self::DISPLAY_SCALE) : 0.0,
            'totalProfit' => (float) bcadd($group['totalProfit'], '0', self::DISPLAY_SCALE),
            'totalProfitPercentage' => $totalProfitPercentage,
            'avgDurationMin' => $group['trades'] > 0 ? (int) round(($group['totalDurationSeconds'] / $group['trades']) / 60) : 0,
            'wins' => $group['wins'],
            'draws' => $group['draws'],
            'losses' => $group['losses'],
        ];
    }
}
