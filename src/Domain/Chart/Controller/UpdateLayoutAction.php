<?php

namespace Stochastix\Domain\Chart\Controller;

use Stochastix\Domain\Chart\Dto\SaveLayoutRequestDto;
use Stochastix\Domain\Chart\Service\ChartLayoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[AsController]
#[Route('/api/charts/layouts/{id}', name: 'stochastix_api_charts_layouts_update', methods: ['PUT'])]
class UpdateLayoutAction extends AbstractController
{
    public function __construct(private readonly ChartLayoutService $layoutService)
    {
    }

    public function __invoke(string $id, #[MapRequestPayload] SaveLayoutRequestDto $requestDto): JsonResponse
    {
        $layout = $this->layoutService->updateLayout($id, $requestDto);

        return $this->json($layout, Response::HTTP_OK, [], [
            AbstractNormalizer::GROUPS => ['layout:read'],
        ]);
    }
}
