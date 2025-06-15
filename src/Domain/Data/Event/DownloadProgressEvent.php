<?php

namespace Stochastix\Domain\Data\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched during data download after each batch is fetched.
 */
class DownloadProgressEvent extends Event
{
    /**
     * @param string|null $jobId                 a unique ID for the download operation
     * @param string      $symbol                the symbol being downloaded
     * @param int         $lastTimestamp         the Unix timestamp (seconds) of the last record in the fetched batch
     * @param int         $recordsFetchedInBatch the number of records fetched in this specific batch
     * @param int         $totalDuration         the total duration of the download request in milliseconds
     * @param int         $currentProgress       the progress in milliseconds
     */
    public function __construct(
        public readonly ?string $jobId,
        public readonly string $symbol,
        public readonly int $lastTimestamp,
        public readonly int $recordsFetchedInBatch,
        public readonly int $totalDuration,
        public readonly int $currentProgress,
    ) {
    }
}
