<?php

namespace Stochastix\Command;

use Stochastix\Domain\Data\Service\IndicatorStorage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'stochastix:data:indicator-info',
    description: 'Reads and displays metadata from an STCHXI indicator data file.',
    aliases: ['stx:data:ind-info']
)]
class DataIndicatorInfoCommand extends Command
{
    public function __construct(private readonly IndicatorStorage $indicatorStorage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file-path', InputArgument::REQUIRED, 'The path to the .stchxi file to inspect.');
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
            $io->title('📊 Stochastix STCHXI File Inspector 📊');
            $io->writeln(" <info>File:</info> {$filePath}");
            $io->writeln(' <info>Size:</info> ' . number_format(filesize($filePath)) . ' bytes');
            $io->newLine();

            // *** MODIFIED: Use the new read() method without reading data blocks ***
            $fileInfo = $this->indicatorStorage->read($filePath, false);

            $io->section('Header Information');
            $io->definitionList(
                ['Magic Number' => $fileInfo['header']['magic']],
                ['Format Version' => $fileInfo['header']['version']],
                ['Value Format Code' => $fileInfo['header']['valformat']],
                ['Timestamp Count' => number_format($fileInfo['header']['timestampcount'])],
                ['Series Count' => number_format($fileInfo['header']['seriescount'])],
            );

            $io->section('Series Directory');
            $headers = ['#', 'Indicator Key', 'Series Key'];
            $rows = [];
            foreach ($fileInfo['directory'] as $index => $entry) {
                $rows[] = [$index, $entry['indicatorKey'], $entry['seriesKey']];
            }
            $io->table($headers, $rows);

            $io->success('File metadata read successfully.');

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $io->error(['An error occurred while inspecting the file:', $e->getMessage()]);
            if ($output->isVerbose()) {
                $io->newLine();
                $io->writeln('<comment>Exception Trace:</comment>');
                $io->writeln($e->getTraceAsString());
            }

            return Command::FAILURE;
        }
    }
}
