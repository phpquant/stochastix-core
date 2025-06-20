<?php

namespace Stochastix\Domain\Chart\Controller;

use Stochastix\Domain\Chart\Service\ChartLayoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[AsController]
#[Route('/api/charts/layouts/{id}', name: 'stochastix_api_charts_layouts_get', methods: ['GET'])]
class GetLayoutAction extends AbstractController
{
    public function __construct(private readonly ChartLayoutService $layoutService)
    {
    }

    public function __invoke(string $id): JsonResponse
    {
        $layout = $this->layoutService->findLayoutById($id);

        return $this->json($layout, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => ['layout:read'],
        ]);
    }
}
