<?php

namespace Stochastix\Domain\Backtesting\Service;

use Ds\Vector;
use Stochastix\Domain\Common\Enum\OhlcvEnum;
use Stochastix\Domain\Common\Enum\TimeframeEnum;
use Stochastix\Domain\Data\Service\BinaryStorageInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MultiTimeframeDataService implements MultiTimeframeDataServiceInterface
{
    public function __construct(
        private BinaryStorageInterface $binaryStorage,
        #[Autowire('%kernel.project_dir%/data/market')]
        private string $baseDataPath,
    ) {
    }

    public function loadAndResample(string $symbol, string $exchangeId, Vector $primaryTimestamps, TimeframeEnum $secondaryTimeframe): array
    {
        $filePath = $this->generateFilePath($exchangeId, $symbol, $secondaryTimeframe->value);
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Data file for secondary timeframe {$secondaryTimeframe->value} not found at: {$filePath}");
        }

        $secondaryRecords = iterator_to_array($this->binaryStorage->readRecordsSequentially($filePath));
        $emptyVector = new Vector(array_fill(0, count($primaryTimestamps), null));
        $emptyData = array_fill_keys(array_column(OhlcvEnum::cases(), 'value'), $emptyVector);

        if (empty($secondaryRecords)) {
            return $emptyData;
        }

        $resampledData = [];
        foreach (OhlcvEnum::cases() as $case) {
            $resampledData[$case->value] = new Vector();
        }

        $secondaryIndex = 0;
        $numSecondaryRecords = count($secondaryRecords);

        foreach ($primaryTimestamps as $primaryTs) {
            while ($secondaryIndex + 1 < $numSecondaryRecords && $secondaryRecords[$secondaryIndex + 1]['timestamp'] <= $primaryTs) {
                ++$secondaryIndex;
            }

            $recordToUse = $secondaryRecords[$secondaryIndex];

            if ($primaryTs < $recordToUse['timestamp']) {
                foreach (OhlcvEnum::cases() as $case) {
                    $resampledData[$case->value]->push(null);
                }
            } else {
                foreach (OhlcvEnum::cases() as $case) {
                    $resampledData[$case->value]->push($recordToUse[$case->value]);
                }
            }
        }

        return $resampledData;
    }

    private function generateFilePath(string $exchangeId, string $symbol, string $timeframe): string
    {
        $sanitizedSymbol = str_replace('/', '_', $symbol);

        return sprintf(
            '%s/%s/%s/%s.stchx',
            rtrim($this->baseDataPath, '/'),
            strtolower($exchangeId),
            strtoupper($sanitizedSymbol),
            strtolower($timeframe)
        );
    }
}
