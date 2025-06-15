<?php

namespace Stochastix\Domain\Data\Message;

use Stochastix\Domain\Data\Dto\DownloadRequestDto;

final readonly class DownloadDataMessage
{
    public function __construct(
        public string $jobId,
        public DownloadRequestDto $requestDto,
    ) {
    }
}
