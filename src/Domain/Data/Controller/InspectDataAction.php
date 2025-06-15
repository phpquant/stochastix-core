<?php

namespace Stochastix\Domain\Data\Controller;

use Stochastix\Domain\Data\Exception\DataFileNotFoundException;
use Stochastix\Domain\Data\Service\DataInspectionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/data/inspect/{exchangeId}/{symbol}/{timeframe}', name: 'stochastix_api_data_inspect', methods: ['GET'])]
class InspectDataAction extends AbstractController
{
    public function __construct(private readonly DataInspectionService $inspectionService)
    {
    }

    public function __invoke(string $exchangeId, string $symbol, string $timeframe): JsonResponse
    {
        try {
            // The symbol in the URL might have a different separator, e.g. '-', so we replace it with '/'.
            $formattedSymbol = str_replace('-', '/', $symbol);

            $inspectionResult = $this->inspectionService->inspect($exchangeId, $formattedSymbol, $timeframe);

            return $this->json($inspectionResult);
        } catch (DataFileNotFoundException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\Throwable $e) {
            // For security, don't expose internal error details in production
            return $this->json(['error' => 'An unexpected error occurred during data inspection.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}
