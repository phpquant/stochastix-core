<?php

namespace Stochastix\Command;

use Stochastix\Domain\Data\Exception\DataFileNotFoundException;
use Stochastix\Domain\Data\Service\DataInspectionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stochastix:data:info',
    description: 'Reads and displays metadata and information from an STCHXBF1 data file.',
    aliases: ['stx:data:info']
)]
class DataInfoCommand extends Command
{
    public function __construct(
        private readonly DataInspectionService $inspectionService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file-path', InputArgument::REQUIRED, 'The path to the .stchx file to inspect.')
            ->addOption('validate', null, InputOption::VALUE_NONE, 'Perform data consistency check (check for gaps).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $filePath = $input->getArgument('file-path');

        if (!file_exists($filePath) || !is_readable($filePath)) {
            $io->error("File not found or not readable: {$filePath}");

            return Command::INVALID;
        }

        try {
            // --- Auto-detect parameters from file path ---
            $timeframe = pathinfo($filePath, PATHINFO_FILENAME);
            $parentDir = dirname($filePath);
            $symbolWithUnderscore = basename($parentDir);
            $exchangeId = basename(dirname($parentDir));
            $symbol = str_replace('_', '/', $symbolWithUnderscore);
            // ---

            $result = $this->inspectionService->inspect($exchangeId, $symbol, $timeframe);

            $io->title('📊 Stochastix STCHXBF1 File Information 📊');
            $io->writeln(" <info>File:</info> {$result['filePath']}");
            $io->writeln(' <info>Size:</info> ' . number_format($result['fileSize']) . ' bytes');
            $io->newLine();

            $io->section('Header Metadata');
            $header = $result['header'];
            $io->definitionList(
                ['Magic Number' => $header['magic']],
                ['Format Version' => $header['version']],
                ['Header Length' => $header['headerLength']],
                ['Record Length' => $header['recordLength']],
                ['Timestamp Format' => $header['tsFormat']],
                ['OHLCV Format' => $header['ohlcvFormat']],
                ['Symbol' => $header['symbol']],
                ['Timeframe' => $header['timeframe']],
                ['Number of Records' => number_format($header['numRecords'])],
            );

            if ($header['numRecords'] > 0) {
                $io->section('Data Sample (Head & Tail)');
                $this->printDataTable($io, $result['sample']);
            } else {
                $io->note('File contains no data records.');
            }

            if ($input->getOption('validate')) {
                $this->printValidationResult($io, $result['validation']);
            }

            if (($result['validation']['status'] ?? 'passed') === 'failed') {
                return Command::FAILURE;
            }

            $io->success('File information retrieved successfully.');

            return Command::SUCCESS;
        } catch (DataFileNotFoundException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        } catch (\Exception $e) {
            $io->error([
                'An error occurred while inspecting the file:',
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

    private function printDataTable(SymfonyStyle $io, array $sample): void
    {
        $headers = ['Timestamp', 'Date (UTC)', 'Open', 'High', 'Low', 'Close', 'Volume'];

        $rows = [];
        foreach ($sample['head'] as $record) {
            $rows[] = $this->formatRow($record);
        }

        if (!empty($sample['tail'])) {
            $rows[] = array_fill(0, count($headers), '...');
            foreach ($sample['tail'] as $record) {
                $rows[] = $this->formatRow($record);
            }
        }

        $io->table($headers, $rows);
    }

    private function formatRow(array $record): array
    {
        return [
            $record['timestamp'],
            $record['utc'],
            number_format($record['open'], 5),
            number_format($record['high'], 5),
            number_format($record['low'], 5),
            number_format($record['close'], 5),
            number_format($record['volume'], 2),
        ];
    }

    private function printValidationResult(SymfonyStyle $io, array $validation): void
    {
        $io->section('🔍 Data Consistency Validation');

        if ($validation['status'] === 'skipped') {
            $io->note($validation['message']);

            return;
        }

        if ($validation['status'] === 'passed') {
            $io->success($validation['message']);

            return;
        }

        $io->error($validation['message']);

        if (!empty($validation['gaps'])) {
            $io->writeln('<comment>Gaps:</comment>');
            foreach ($validation['gaps'] as $gap) {
                $io->warning(sprintf(
                    '  - At index %d: Diff: %ds, Expected: %ds',
                    $gap['index'],
                    $gap['diff'],
                    $gap['expected']
                ));
            }
        }
        if (!empty($validation['duplicates'])) {
            $io->writeln('<comment>Duplicates:</comment>');
            foreach ($validation['duplicates'] as $dup) {
                $io->warning(sprintf(
                    '  - At index %d: Timestamp %d',
                    $dup['index'],
                    $dup['timestamp']
                ));
            }
        }
        if (!empty($validation['outOfOrder'])) {
            $io->writeln('<comment>Out of Order:</comment>');
            foreach ($validation['outOfOrder'] as $ooo) {
                $io->error(sprintf(
                    '  - At index %d: Timestamp %d -> %d',
                    $ooo['index'],
                    $ooo['previous'],
                    $ooo['current']
                ));
            }
        }
    }
}
