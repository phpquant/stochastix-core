<?php

namespace Stochastix\Domain\Data\Service;

use Stochastix\Domain\Data\Exception\DataFileNotFoundException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

readonly class DataInspectionService
{
    private \DateTimeZone $utcZone;

    public function __construct(
        private BinaryStorageInterface $binaryStorage,
        #[Autowire('%kernel.project_dir%/data/market')]
        private string $baseDataPath,
    ) {
        $this->utcZone = new \DateTimeZone('UTC');
    }

    /**
     * Inspects a data file and returns a structured array with metadata, samples, and validation results.
     *
     * @throws DataFileNotFoundException
     */
    public function inspect(string $exchangeId, string $symbol, string $timeframe): array
    {
        $filePath = $this->generateFilePath($exchangeId, $symbol, $timeframe);

        if (!file_exists($filePath)) {
            throw new DataFileNotFoundException("Data file not found at path: {$filePath}");
        }

        $header = $this->binaryStorage->readHeader($filePath);
        $recordCount = $header['numRecords'];
        $head = [];
        $tail = [];

        if ($recordCount > 0) {
            $headCount = min(5, $recordCount);
            for ($i = 0; $i < $headCount; ++$i) {
                $record = $this->binaryStorage->readRecordByIndex($filePath, $i);
                if ($record) {
                    $head[] = $this->formatRecord($record);
                }
            }

            if ($recordCount > 10) {
                $tailStart = max($headCount, $recordCount - 5);
                for ($i = $tailStart; $i < $recordCount; ++$i) {
                    $record = $this->binaryStorage->readRecordByIndex($filePath, $i);
                    if ($record) {
                        $tail[] = $this->formatRecord($record);
                    }
                }
            }
        }

        $validation = $this->validateDataConsistency($filePath, $header);

        return [
            'filePath' => $filePath,
            'fileSize' => filesize($filePath),
            'header' => $header,
            'sample' => [
                'head' => $head,
                'tail' => $tail,
            ],
            'validation' => $validation,
        ];
    }

    private function generateFilePath(string $exchangeId, string $symbol, string $timeframe): string
    {
        $sanitizedSymbol = str_replace('/', '_', $symbol);

        return sprintf(
            '%s/%s/%s/%s.stchx',
            rtrim($this->baseDataPath, '/'),
            strtolower($exchangeId),
            strtoupper($sanitizedSymbol),
            $timeframe
        );
    }

    private function formatRecord(array $record): array
    {
        return [
            'timestamp' => $record['timestamp'],
            'utc' => \DateTimeImmutable::createFromFormat('U', (string) $record['timestamp'])
                ->setTimezone($this->utcZone)
                ->format('Y-m-d H:i:s'),
            'open' => $record['open'],
            'high' => $record['high'],
            'low' => $record['low'],
            'close' => $record['close'],
            'volume' => $record['volume'],
        ];
    }

    private function timeframeToSeconds(string $timeframe): ?int
    {
        $unit = substr($timeframe, -1);
        $value = (int) substr($timeframe, 0, -1);

        if ($value <= 0) {
            return null;
        }

        return match ($unit) {
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => null, // 'M' is handled by a separate date-aware method
        };
    }

    private function validateDataConsistency(string $filePath, array $header): array
    {
        $recordCount = $header['numRecords'];
        $timeframe = $header['timeframe'];

        if ($recordCount < 2) {
            return ['status' => 'skipped', 'message' => 'Need at least 2 records to check for gaps.'];
        }

        // Branch to a date-aware check for monthly data
        if (str_ends_with($timeframe, 'M')) {
            return $this->validateMonthlyGaps($filePath, (int) $timeframe);
        }

        // Use fixed-second interval check for all other timeframes
        $expectedInterval = $this->timeframeToSeconds($timeframe);
        if ($expectedInterval === null) {
            return ['status' => 'skipped', 'message' => "Gap validation for timeframe '{$timeframe}' is not supported."];
        }

        $records = $this->binaryStorage->readRecordsSequentially($filePath);
        $previousTimestamp = null;
        $gaps = [];
        $duplicates = [];
        $outOfOrder = [];
        $index = 0;

        foreach ($records as $record) {
            $currentTimestamp = $record['timestamp'];

            if ($previousTimestamp !== null) {
                $diff = $currentTimestamp - $previousTimestamp;

                if ($diff <= 0) {
                    if ($diff === 0) {
                        $duplicates[] = ['index' => $index, 'timestamp' => $currentTimestamp];
                    } else {
                        $outOfOrder[] = ['index' => $index, 'previous' => $previousTimestamp, 'current' => $currentTimestamp];
                    }
                } elseif ($diff !== $expectedInterval) {
                    $gaps[] = ['index' => $index, 'previous' => $previousTimestamp, 'current' => $currentTimestamp, 'diff' => $diff, 'expected' => $expectedInterval];
                }
            }
            $previousTimestamp = $currentTimestamp;
            ++$index;
        }

        $totalIssues = count($gaps) + count($duplicates) + count($outOfOrder);

        return [
            'status' => $totalIssues > 0 ? 'failed' : 'passed',
            'message' => $totalIssues > 0 ? "Found {$totalIssues} issue(s)." : 'Data appears consistent.',
            'gaps' => $gaps,
            'duplicates' => $duplicates,
            'outOfOrder' => $outOfOrder,
        ];
    }

    private function validateMonthlyGaps(string $filePath, int $monthStep): array
    {
        $records = $this->binaryStorage->readRecordsSequentially($filePath);
        $previousDateTime = null;
        $gaps = [];
        $duplicates = [];
        $index = 0;

        foreach ($records as $record) {
            $currentDateTime = \DateTimeImmutable::createFromFormat('U', (string) $record['timestamp'])
                ->setTimezone($this->utcZone);

            if ($previousDateTime !== null) {
                $expectedDateTime = $previousDateTime->modify("+{$monthStep} month");

                // Check for duplicates
                if ($currentDateTime->format('Y-m') === $previousDateTime->format('Y-m')) {
                    $duplicates[] = ['index' => $index, 'month' => $currentDateTime->format('Y-m')];
                }
                // Check for gaps
                elseif ($currentDateTime->format('Y-m') !== $expectedDateTime->format('Y-m')) {
                    $gaps[] = [
                        'index' => $index,
                        'previous' => $previousDateTime->format('Y-m'),
                        'current' => $currentDateTime->format('Y-m'),
                        'expected' => $expectedDateTime->format('Y-m'),
                    ];
                }
            }

            $previousDateTime = $currentDateTime;
            ++$index;
        }

        $totalIssues = count($gaps) + count($duplicates);

        return [
            'status' => $totalIssues > 0 ? 'failed' : 'passed',
            'message' => $totalIssues > 0 ? "Found {$totalIssues} issue(s) in monthly data." : 'Monthly data appears consistent.',
            'gaps' => $gaps,
            'duplicates' => $duplicates,
            'outOfOrder' => [], // Out-of-order is implicitly a gap in this context
        ];
    }
}
