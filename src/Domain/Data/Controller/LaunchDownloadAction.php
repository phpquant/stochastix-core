<?php

namespace Stochastix\Domain\Data\Controller;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Data\Dto\DownloadRequestDto;
use Stochastix\Domain\Data\Message\DownloadDataMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/data/download', name: 'stochastix_api_data_download', methods: ['POST'])]
class LaunchDownloadAction extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(#[MapRequestPayload] DownloadRequestDto $requestDto): JsonResponse
    {
        try {
            $jobId = uniqid('download_', true);
            $message = new DownloadDataMessage($jobId, $requestDto);

            $this->messageBus->dispatch($message);

            $this->logger->info('Data download has been queued.', [
                'jobId' => $jobId,
                'exchange' => $requestDto->exchangeId,
                'symbol' => $requestDto->symbol,
            ]);

            return $this->json(
                ['status' => 'queued', 'jobId' => $jobId],
                Response::HTTP_ACCEPTED
            );
        } catch (\Throwable $e) {
            $this->logger->error('Failed to queue data download: {message}', ['message' => $e->getMessage()]);

            return $this->json(['error' => 'Failed to queue data download process.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
