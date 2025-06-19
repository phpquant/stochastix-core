<?php

namespace Stochastix\Domain\Data\EventSubscriber;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Event\DownloadProgressEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class DownloadProgressSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private HubInterface $mercureHub,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            DownloadProgressEvent::class => 'onDownloadProgress',
        ];
    }

    public function onDownloadProgress(DownloadProgressEvent $event): void
    {
        if ($event->jobId === null) {
            return;
        }

        $topic = sprintf('/data/download/%s/progress', $event->jobId);

        $percentage = 0;
        if ($event->totalDuration > 0) {
            $percentage = round(($event->currentProgress / $event->totalDuration) * 100);
        }

        $data = [
            'status' => 'running',
            'lastTimestamp' => $event->lastTimestamp,
            'progress' => $percentage,
            'message' => "Fetched {$event->recordsFetchedInBatch} records up to " . gmdate('Y-m-d H:i:s', $event->lastTimestamp),
        ];

        try {
            $update = new Update($topic, json_encode($data, JSON_THROW_ON_ERROR));
            $this->mercureHub->publish($update);
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to publish progress update to Mercure.', [
                'jobId' => $event->jobId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
