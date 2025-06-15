<?php

namespace Stochastix\Command;

use Psr\EventDispatcher\EventDispatcherInterface;
use Stochastix\Domain\Data\Event\DownloadProgressEvent;
use Stochastix\Domain\Data\Service\OhlcvDownloader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stochastix:data:download',
    description: 'Downloads OHLCV historical data from an exchange and stores it in STCHXBF1 format.',
    aliases: ['stx:data:dl']
)]
class DataDownloadCommand extends Command
{
    private readonly \DateTimeZone $utcZone;
    private ?ProgressBar $progressBar = null;

    public function __construct(
        private readonly OhlcvDownloader $ohlcvDownloader,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
        parent::__construct();
        $this->utcZone = new \DateTimeZone('UTC');
    }

    protected function configure(): void
    {
        $this
            ->setHelp('This command allows you to download historical OHLCV data for a specific instrument from an exchange between two dates.')
            ->addArgument(
                'exchange',
                InputArgument::REQUIRED,
                'The exchange ID (e.g., okx, binance)'
            )
            ->addArgument(
                'symbol',
                InputArgument::REQUIRED,
                'The trading symbol (e.g., ETH/USDT)'
            )
            ->addArgument(
                'timeframe',
                InputArgument::REQUIRED,
                'The timeframe (e.g., 1m, 5m, 1h, 1d)'
            )
            ->addOption(
                'start-date',
                'S',
                InputOption::VALUE_REQUIRED,
                'The UTC start date/time (Format: Y-m-d[THH:MM:SS])'
            )
            ->addOption(
                'end-date',
                'E',
                InputOption::VALUE_OPTIONAL,
                'The UTC end date/time (Format: Y-m-d[THH:MM:SS]). Defaults to "now".'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $listener = null;

        try {
            $exchange = $input->getArgument('exchange');
            $symbol = $input->getArgument('symbol');
            $timeframe = $input->getArgument('timeframe');

            $startDateStr = $input->getOption('start-date');
            $endDateStr = $input->getOption('end-date');

            $startDate = $this->parseDate($startDateStr, $io, 'Start date');
            $endDate = $endDateStr
                ? $this->parseDate($endDateStr, $io, 'End date')
                : new \DateTimeImmutable('now', $this->utcZone);

            if ($startDate >= $endDate) {
                $io->error('Start date must be strictly before the end date.');

                return Command::INVALID;
            }

            $io->title('🚀 Stochastix OHLCV Data Downloader 🚀');
            $io->newLine();
            $io->section('Download Progress');

            $totalDuration = $endDate->getTimestamp() - $startDate->getTimestamp();
            $this->progressBar = new ProgressBar($output, $totalDuration > 0 ? $totalDuration : 1);
            $this->progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% | Fetched until: <info>%message%</info>');
            $this->progressBar->setMessage('Starting...');
            $this->progressBar->start();

            $listener = function (DownloadProgressEvent $event) use ($startDate) {
                if ($this->progressBar) {
                    $progress = $event->lastTimestamp - $startDate->getTimestamp();
                    $this->progressBar->setProgress(max(0, $progress));
                    $date = \DateTimeImmutable::createFromFormat('U', (string) $event->lastTimestamp)
                        ->setTimezone($this->utcZone)
                        ->format('Y-m-d H:i:s');
                    $this->progressBar->setMessage("{$date} ({$event->recordsFetchedInBatch} recs)");
                }
            };
            $this->eventDispatcher->addListener(DownloadProgressEvent::class, $listener);

            $filePath = $this->ohlcvDownloader->download(
                $exchange,
                $symbol,
                $timeframe,
                $startDate,
                $endDate
            );

            $this->progressBar->finish();
            $io->newLine(2);
            $io->success([
                'Download Complete!',
                "Data successfully saved to: {$filePath}",
            ]);

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->progressBar?->finish(); // Ensure it finishes on error too
            $io->newLine(); // Move below bar before error message
            $io->error([
                '💥 An error occurred during download:',
                $e->getMessage(),
            ]);

            if ($output->isVerbose()) {
                $io->newLine();
                $io->writeln('<comment>Exception Trace:</comment>');
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        } finally {
            if ($listener !== null) {
                $this->eventDispatcher->removeListener(DownloadProgressEvent::class, $listener);
            }
        }
    }

    private function parseDate(string $dateString, SymfonyStyle $io, string $label): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($dateString, $this->utcZone);
        } catch (\Exception $e) {
            $io->error("Invalid {$label} format: '{$dateString}'. Please use Y-m-d or Y-m-dTHH:MM:SS.");
            throw new \RuntimeException('Invalid date format', 0, $e); // Re-throw to stop execution
        }
    }
}
