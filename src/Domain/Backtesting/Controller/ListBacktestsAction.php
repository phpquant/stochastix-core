<?php

namespace Stochastix\Domain\Backtesting\Controller;

use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class ListBacktestsAction extends AbstractController
{
    public function __construct(
        private readonly BacktestResultRepositoryInterface $resultRepository
    ) {
    }

    #[Route('/api/backtests', name: 'stochastix_api_backtests_list', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $backtestMetadata = $this->resultRepository->findAllMetadata();

        return $this->json($backtestMetadata);
    }
}
