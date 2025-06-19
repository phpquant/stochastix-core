<?php

namespace Stochastix\Domain\Data\Controller;

use Stochastix\Domain\Data\Service\MarketDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/data/exchanges', name: 'stochastix_api_data_exchanges', methods: ['GET'])]
class GetExchangesAction extends AbstractController
{
    public function __construct(private readonly MarketDataService $marketDataService)
    {
    }

    public function __invoke(): JsonResponse
    {
        return $this->json($this->marketDataService->getExchanges());
    }
}
