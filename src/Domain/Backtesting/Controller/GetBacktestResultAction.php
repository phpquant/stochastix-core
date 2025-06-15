<?php

namespace Stochastix\Domain\Backtesting\Controller;

use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/backtests/{runId}', name: 'stochastix_api_backtests_get_result', methods: ['GET'])]
class GetBacktestResultAction extends AbstractController
{
    public function __invoke(BacktestResultRepositoryInterface $resultRepository, string $runId): JsonResponse
    {
        $results = $resultRepository->find($runId);

        if ($results === null) {
            return $this->json(
                ['error' => 'Backtest result not found.'],
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->json($results);
    }
}
