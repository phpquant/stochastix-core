<?php

namespace Stochastix\Domain\Strategy\Controller;

use Stochastix\Domain\Strategy\Service\StrategyRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/strategies', name: 'stochastix_api_strategies_list', methods: ['GET'])]
class GetStrategiesAction extends AbstractController
{
    public function __invoke(StrategyRegistry $strategyRegistry): JsonResponse
    {
        return $this->json($strategyRegistry->getStrategyDefinitions());
    }
}
