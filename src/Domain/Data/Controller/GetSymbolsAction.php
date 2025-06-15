<?php

namespace Stochastix\Domain\Data\Controller;

use Stochastix\Domain\Data\Exception\ExchangeException;
use Stochastix\Domain\Data\Service\MarketDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/data/symbols/{exchangeId}', name: 'stochastix_api_data_symbols', methods: ['GET'])]
class GetSymbolsAction extends AbstractController
{
    public function __construct(private readonly MarketDataService $marketDataService)
    {
    }

    public function __invoke(string $exchangeId): JsonResponse
    {
        try {
            $symbols = $this->marketDataService->getFuturesSymbols($exchangeId);

            return $this->json($symbols);
        } catch (ExchangeException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
