<?php

namespace Stochastix\Domain\Chart\Controller;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Chart\Dto\ChartIndicatorRequestDto;
use Stochastix\Domain\Chart\Service\ChartIndicatorService;
use Stochastix\Domain\Data\Exception\DataFileNotFoundException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/charts/indicators', name: 'stochastix_api_charts_indicators_get', methods: ['POST'])]
class GetChartIndicatorsAction extends AbstractController
{
    public function __construct(
        private readonly ChartIndicatorService $chartIndicatorService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(#[MapRequestPayload] ChartIndicatorRequestDto $requestDto): JsonResponse
    {
        try {
            $data = $this->chartIndicatorService->getIndicatorData($requestDto);
            return $this->json($data);
        } catch (DataFileNotFoundException $e) {
            $this->logger->warning($e->getMessage());
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            $this->logger->error('An unexpected error occurred while fetching chart indicator data: {message}', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->json(['error' => 'An internal error occurred.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
