<?php

namespace Stochastix\Domain\Chart\Controller;

use Stochastix\Domain\Chart\Service\ChartLayoutService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/charts/layouts/{id}', name: 'stochastix_api_charts_layouts_delete', methods: ['DELETE'])]
class DeleteLayoutAction extends AbstractController
{
    public function __construct(private readonly ChartLayoutService $layoutService)
    {
    }

    public function __invoke(string $id): Response
    {
        $this->layoutService->deleteLayout($id);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}

