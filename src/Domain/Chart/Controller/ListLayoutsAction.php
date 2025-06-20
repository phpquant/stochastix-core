<?php

namespace Stochastix\Domain\Chart\Controller;

use Stochastix\Domain\Chart\Repository\ChartLayoutRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;

#[AsController]
#[Route('/api/charts/layouts', name: 'stochastix_api_charts_layouts_list', methods: ['GET'])]
class ListLayoutsAction extends AbstractController
{
    public function __construct(private readonly ChartLayoutRepository $repository)
    {
    }

    public function __invoke(): JsonResponse
    {
        $layouts = $this->repository->findBy([], ['updatedAt' => 'DESC']);

        return $this->json($layouts, Response::HTTP_OK, [], [
            // Note: We might want a smaller 'layout:list' group later
            AbstractNormalizer::GROUPS => ['layout:read'],
        ]);
    }
}
