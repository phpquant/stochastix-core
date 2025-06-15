<?php

namespace Stochastix\Domain\Backtesting\Controller;

use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/backtests/{runId}', name: 'stochastix_api_backtests_delete', methods: ['DELETE'])]
class DeleteBacktestResultAction extends AbstractController
{
    public function __construct(
        private readonly BacktestResultRepositoryInterface $resultRepository
    ) {
    }

    public function __invoke(string $runId): Response
    {
        $deleted = $this->resultRepository->delete($runId);

        if (!$deleted) {
            return new Response(null, Response::HTTP_NOT_FOUND);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
