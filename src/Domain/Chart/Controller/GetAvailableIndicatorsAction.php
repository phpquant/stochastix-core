<?php

namespace Stochastix\Domain\Chart\Controller;

use Stochastix\Domain\Chart\Service\IndicatorDiscoveryService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/charts/available-indicators', name: 'stochastix_api_charts_available_indicators', methods: ['GET'])]
class GetAvailableIndicatorsAction extends AbstractController
{
    public function __construct(private readonly IndicatorDiscoveryService $discoveryService)
    {
    }

    public function __invoke(): JsonResponse
    {
        return $this->json($this->discoveryService->getAvailableIndicators());
    }
}
