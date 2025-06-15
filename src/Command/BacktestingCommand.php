<?php

namespace Stochastix\Command;

use Stochastix\Domain\Backtesting\Dto\BacktestConfiguration;
use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Stochastix\Domain\Backtesting\Service\Backtester;
use Stochastix\Domain\Backtesting\Service\BacktestResultSaver;
use Stochastix\Domain\Backtesting\Service\ConfigurationResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Stopwatch\Stopwatch;
use Symfony\Component\Stopwatch\StopwatchEvent;

#[AsCommand(
    name: 'stochastix:backtesting',
    description: 'Runs a backtest based on a strategy and configuration overrides.',
    aliases: ['stx:backtest']
)]
class BacktestingCommand extends Command
{
    private const int DISPLAY_SCALE_CURRENCY = 2;
    private const int DISPLAY_SCALE_PERCENT = 2;
    private const int DISPLAY_SCALE_PRICE = 5;
    private const int DISPLAY_SCALE_QTY = 6;

    public function __construct(
        private readonly Backtester $backtester,
        private readonly ConfigurationResolver $configResolver,
        private readonly BacktestResultRepositoryInterface $resultRepository,
        private readonly BacktestResultSaver $resultSaver
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Runs a backtest. Specify a strategy alias and optionally override any configuration (from #[Input] or stochastix.yaml defaults) using options.')
            ->addArgument('strategy-alias', InputArgument::REQUIRED, 'The alias of the strategy to run (defined in #[AsStrategy]).')
            ->addOption('symbol', 's', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Override symbols.')
            ->addOption('timeframe', 't', InputOption::VALUE_REQUIRED, 'Override timeframe.')
            ->addOption('start-date', 'S', InputOption::VALUE_REQUIRED, 'Override start date (YYYY-MM-DD).')
            ->addOption('end-date', 'E', InputOption::VALUE_REQUIRED, 'Override end date (YYYY-MM-DD).')
            ->addOption('input', 'i', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Override a strategy input (e.g., -i ema:10).')
            ->addOption('config', 'c', InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Override a global config (e.g., -c initial_capital:50000).')
            ->addOption('load-config', 'l', InputOption::VALUE_REQUIRED, 'Load config from JSON file.')
            ->addOption('save-config', null, InputOption::VALUE_REQUIRED, 'Save config to JSON file and exit.')
            ->addOption('annual-risk-free-rate', null, InputOption::VALUE_REQUIRED, 'Annual risk-free rate for Sharpe Ratio calculation (e.g., 0.02 for 2%).', 0.02);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $strategyAlias = $input->getArgument('strategy-alias');

        $stopwatch = new Stopwatch(true);
        $stopwatch->start('backtest_execute');

        $io->title(sprintf('🚀 Stochastix Backtester Initializing: %s 🚀', $strategyAlias));

        try {
            $io->text('Resolving configuration...');
            $config = $this->configResolver->resolve($input);
            $io->text('Configuration resolved.');
            $io->newLine();

            if ($savePath = $input->getOption('save-config')) {
                $this->saveConfigToJson($config, $savePath);
                $io->success("Configuration saved to {$savePath}. Exiting as requested.");
                $event = $stopwatch->stop('backtest_execute');
                $this->displayExecutionTime($io, $event);

                return Command::SUCCESS;
            }

            $io->section('Final Backtest Configuration');
            $definitions = [
                ['Strategy Alias' => $config->strategyAlias],
                ['Strategy Class' => $config->strategyClass],
                ['Symbols' => implode(', ', $config->symbols)],
                ['Timeframe' => $config->timeframe->value],
                ['Start Date' => $config->startDate ? $config->startDate->format('Y-m-d') : 'Full History (Start)'],
                ['End Date' => $config->endDate ? $config->endDate->format('Y-m-d') : 'Full History (End)'],
                ['Initial Capital' => $this->formatNumber($config->initialCapital, self::DISPLAY_SCALE_CURRENCY)],
                ['Stake Amount' => $config->stakeAmountConfig !== null ? (is_numeric($config->stakeAmountConfig) && $config->stakeAmountConfig > 0 && $config->stakeAmountConfig <= 1 ? sprintf('%.2f%%', (float) $config->stakeAmountConfig * 100) : $config->stakeAmountConfig) : 'N/A'],
                ['Exchange' => $config->dataSourceExchangeId],
            ];

            if (!empty($config->strategyInputs)) {
                $definitions[] = new TableSeparator();
                $definitions[] = ['<info>Strategy Inputs</info>' => ''];
                foreach ($config->strategyInputs as $key => $value) {
                    $displayValue = is_scalar($value) ? (string) $value : json_encode($value);
                    $definitions[] = ['  ' . $key => $displayValue];
                }
            }
            $io->definitionList(...$definitions);

            $io->section('Starting Backtest Run...');
            $results = $this->backtester->run($config);
            $runId = $this->resultRepository->generateRunId($config->strategyAlias);
            $io->note("Generated Run ID: {$runId}");

            $this->resultSaver->save($runId, $results);

            $io->section('Backtest Performance Summary');
            $this->displaySummaryStats($io, $results);
            $this->displayTradesLog($io, $results['closedTrades']);
            $this->displayOpenPositionsLog($io, $results['openPositions'] ?? []); // NEW

            $io->newLine();
            $event = $stopwatch->stop('backtest_execute');
            $this->displayExecutionTime($io, $event);
            $io->newLine();
            $io->success(sprintf('Backtest for "%s" finished successfully.', $strategyAlias));

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $event = $stopwatch->stop('backtest_execute');
            $this->displayExecutionTime($io, $event, true);

            $io->error([
                '💥 An error occurred:',
                $e->getMessage(),
            ]);
            if ($output->isVerbose()) {
                $io->newLine();
                $io->writeln('<comment>Exception Trace:</comment>');
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }

    private function displayExecutionTime(SymfonyStyle $io, StopwatchEvent $event, bool $errorOccurred = false): void
    {
        $messagePrefix = $errorOccurred ? '📊 Backtest ran for' : '📊 Backtest finished in';
        $io->writeln(sprintf(
            '%s: <info>%.2f ms</info> / Memory usage: <info>%.2f MB</info>',
            $messagePrefix,
            $event->getDuration(),
            $event->getMemory() / (1024 ** 2)
        ));
    }

    private function displaySummaryStats(SymfonyStyle $io, array $results): void
    {
        $stats = $results['statistics']['summaryMetrics'];

        $definitions = [
            ['Initial Capital' => $this->formatNumber($stats['startingBalance'], self::DISPLAY_SCALE_CURRENCY)],
            ['Final Capital' => $this->formatNumber($stats['finalBalance'], self::DISPLAY_SCALE_CURRENCY)],
            ['Total Net Profit' => $this->formatNumber($stats['absProfit'], self::DISPLAY_SCALE_CURRENCY)],
            ['Total Net Profit %' => $this->formatNumber($stats['totalProfitPercentage'], self::DISPLAY_SCALE_PERCENT) . '%'],
            new TableSeparator(),
            ['Total Trades' => $stats['totalTrades']],
            ['Profit Factor' => $this->formatNumber($stats['profitFactor'], 2)],
            new TableSeparator(),
            ['Sharpe Ratio' => $this->formatNumber($stats['sharpe'], 3)],
            ['Max Drawdown' => $this->formatNumber($stats['maxAccountUnderwaterPercentage'], self::DISPLAY_SCALE_PERCENT) . '%'],
        ];

        $io->definitionList(...$definitions);
    }

    private function displayTradesLog(SymfonyStyle $io, array $closedTrades): void
    {
        if (empty($closedTrades)) {
            $io->note('No closed trades to display.');

            return;
        }

        $io->section('Closed Trades Log');
        $headers = ['#', 'Symbol', 'Dir', 'Enter Tag', 'Exit Tag', 'Entry Time', 'Exit Time', 'Qty', 'P&L'];
        $rows = [];

        foreach ($closedTrades as $trade) {
            $rows[] = [
                $trade['tradeNumber'],
                $trade['symbol'],
                ucfirst($trade['direction']),
                implode(', ', $trade['enter_tags'] ?? []),
                implode(', ', $trade['exit_tags'] ?? []),
                $trade['entryTime'],
                $trade['exitTime'],
                $this->formatNumber($trade['quantity'], self::DISPLAY_SCALE_QTY),
                $this->formatNumber($trade['pnl'], self::DISPLAY_SCALE_CURRENCY),
            ];
        }

        $io->table($headers, $rows);
    }

    private function displayOpenPositionsLog(SymfonyStyle $io, array $openPositions): void
    {
        if (empty($openPositions)) {
            return;
        }

        $io->section('Open Positions at End of Backtest');
        $headers = ['Symbol', 'Dir', 'Entry Time', 'Qty', 'Entry Price', 'Current Price', 'Unrealized P&L'];
        $rows = [];

        foreach ($openPositions as $position) {
            $rows[] = [
                $position['symbol'],
                ucfirst($position['direction']),
                $position['entryTime'],
                $this->formatNumber($position['quantity'], self::DISPLAY_SCALE_QTY),
                $this->formatNumber($position['entryPrice'], self::DISPLAY_SCALE_PRICE),
                $this->formatNumber($position['currentPrice'], self::DISPLAY_SCALE_PRICE),
                $this->formatNumber($position['unrealizedPnl'], self::DISPLAY_SCALE_CURRENCY),
            ];
        }

        $io->table($headers, $rows);
    }

    private function formatNumber($number, int $scale): string
    {
        if (!is_numeric($number)) {
            return (string) $number;
        }

        return number_format((float) $number, $scale, '.', '');
    }

    private function saveConfigToJson(BacktestConfiguration $config, string $filePath): void
    {
        $dataToSave = [
            'core' => [
                'strategy_alias' => $config->strategyAlias,
                'symbols' => $config->symbols,
                'timeframe' => $config->timeframe->value,
                'start_date' => $config->startDate?->format('Y-m-d'),
                'end_date' => $config->endDate?->format('Y-m-d'),
                'initial_capital' => $config->initialCapital,
                'stake_currency' => $config->stakeCurrency,
                'stake_amount' => $config->stakeAmountConfig,
                'commission' => $config->commissionConfig,
                'data_source' => [
                    'type' => $config->dataSourceType,
                    'exchange_id' => $config->dataSourceExchangeId,
                    'options' => $config->dataSourceOptions,
                ],
            ],
            'inputs' => $config->strategyInputs,
        ];

        $json = json_encode($dataToSave, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        file_put_contents($filePath, $json);
    }

    private function formatDurationFromDays(float $totalDays): string
    {
        if ($totalDays < 0) {
            return 'N/A (invalid duration)';
        }

        $totalSeconds = (int) round($totalDays * 24 * 60 * 60);

        if ($totalSeconds < 60) { // Less than a minute
            return $totalSeconds . ' second' . ($totalSeconds === 1 ? '' : 's');
        }

        $days = floor($totalDays);
        $remainingHoursTotal = ($totalDays - $days) * 24;
        $hours = floor($remainingHoursTotal);
        $remainingMinutesTotal = ($remainingHoursTotal - $hours) * 60;
        $minutes = floor($remainingMinutesTotal);

        $parts = [];
        if ($days > 0) {
            $parts[] = sprintf('%d day%s', $days, $days > 1 ? 's' : '');
        }
        if ($hours > 0) {
            $parts[] = sprintf('%d hour%s', $hours, $hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = sprintf('%d minute%s', $minutes, $minutes > 1 ? 's' : '');
        }

        if (empty($parts)) {
            // This case should ideally be covered by the $totalSeconds < 60 check,
            // but as a fallback if totalDays was extremely small but not negative.
            if ($totalSeconds > 0) {
                return $totalSeconds . ' second' . ($totalSeconds === 1 ? '' : 's');
            }

            return '0 minutes'; // Or "Less than a minute" if preferred
        }

        return implode(' ', $parts);
    }
}
