<?php

namespace Stochastix\Domain\Data\MessageHandler;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Exception\DownloadCancelledException;
use Stochastix\Domain\Data\Message\DownloadDataMessage;
use Stochastix\Domain\Data\Service\OhlcvDownloader;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DownloadDataMessageHandler
{
    private \DateTimeZone $utcZone;

    public function __construct(
        private OhlcvDownloader $downloader,
        private HubInterface $mercureHub,
        private LoggerInterface $logger,
    ) {
        $this->utcZone = new \DateTimeZone('UTC');
    }

    public function __invoke(DownloadDataMessage $message): void
    {
        $jobId = $message->jobId;
        $dto = $message->requestDto;
        $topic = sprintf('/data/download/%s/progress', $jobId);

        $this->logger->info('Handler started for data download job: {jobId}', ['jobId' => $jobId]);

        try {
            $this->publishUpdate($topic, [
                'status' => 'running',
                'progress' => 0,
                'message' => 'Initializing download...',
            ]);

            $startDate = new \DateTimeImmutable($dto->startDate, $this->utcZone);
            $endDate = new \DateTimeImmutable($dto->endDate, $this->utcZone);

            $this->downloader->download(
                $dto->exchangeId,
                $dto->symbol,
                $dto->timeframe,
                $startDate,
                $endDate,
                $dto->forceOverwrite,
                $jobId
            );

            $this->publishUpdate($topic, [
                'status' => 'completed',
                'progress' => 100,
                'message' => 'Download completed successfully.',
            ]);
        } catch (DownloadCancelledException $e) {
            $this->logger->info('Data download job {jobId} was cancelled.', ['jobId' => $jobId, 'reason' => $e->getMessage()]);
            $this->publishUpdate($topic, ['status' => 'cancelled', 'message' => 'Download was cancelled by the user.']);
        } catch (\Throwable $e) {
            $this->logger->error('Data download job {jobId} failed: {message}', [
                'jobId' => $jobId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->publishUpdate($topic, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function publishUpdate(string $topic, array $data): void
    {
        try {
            $update = new Update($topic, json_encode($data, JSON_THROW_ON_ERROR));
            $this->mercureHub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish update to Mercure. The process will continue.', [
                'topic' => $topic,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
