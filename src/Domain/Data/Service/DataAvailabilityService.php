<?php

namespace Stochastix\Domain\Data\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

final readonly class DataAvailabilityService
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/data/market')]
        private string $marketDataPath,
        private BinaryStorageInterface $binaryStorage,
        private LoggerInterface $logger
    ) {
    }

    public function getManifest(): array
    {
        if (!is_dir($this->marketDataPath)) {
            return [];
        }

        $finder = new Finder();
        $finder->in($this->marketDataPath)->files()->name('*.stchx');

        $manifest = [];

        foreach ($finder as $file) {
            try {
                // Path looks like: .../data/market/{exchange}/{symbol_dir}/{timeframe}.stchx
                $timeframe = $file->getBasename('.stchx');
                $symbolDir = $file->getRelativePath();
                $pathParts = explode('/', $symbolDir);

                if (count($pathParts) < 2) {
                    continue;
                }

                $exchange = $pathParts[0];
                $symbol = str_replace('_', '/', $pathParts[1]);

                // --- CHANGE IS HERE: Use getPathname() for better reliability ---
                $header = $this->binaryStorage->readHeader($file->getPathname());

                if ($header['numRecords'] === 0) {
                    continue;
                }

                $firstRecord = $this->binaryStorage->readRecordByIndex($file->getPathname(), 0);
                $lastRecord = $this->binaryStorage->readRecordByIndex($file->getPathname(), $header['numRecords'] - 1);
                // --- END CHANGE ---

                $timeframeData = [
                    'timeframe' => $timeframe,
                    'startDate' => gmdate('Y-m-d\TH:i:s\Z', $firstRecord['timestamp']),
                    'endDate' => gmdate('Y-m-d\TH:i:s\Z', $lastRecord['timestamp']),
                    'recordCount' => $header['numRecords'],
                ];

                if (!isset($manifest[$symbol])) {
                    $manifest[$symbol] = [
                        'symbol' => $symbol,
                        'exchange' => $exchange,
                        'timeframes' => [],
                    ];
                }

                $manifest[$symbol]['timeframes'][] = $timeframeData;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to process market data file: {file}', [
                    'file' => $file->getPathname(), // Also use getPathname() in error logging
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return array_values($manifest);
    }
}
