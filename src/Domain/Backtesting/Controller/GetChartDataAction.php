<?php

namespace Stochastix\Domain\Backtesting\Controller;

use Stochastix\Domain\Backtesting\Service\ChartDataService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/chart-data/{runId}', name: 'stochastix_api_chart_data', methods: ['GET'])]
class GetChartDataAction extends AbstractController
{
    public function __construct(private readonly ChartDataService $chartDataService)
    {
    }

    public function __invoke(Request $request, string $runId): JsonResponse
    {
        try {
            $from = $request->query->get('fromTimestamp');
            $to = $request->query->get('toTimestamp');
            $countback = $request->query->get('countback');

            $chartData = $this->chartDataService->getChartData(
                $runId,
                $from ? (int) $from : null,
                $to ? (int) $to : null,
                $countback ? (int) $countback : null
            );

            return $this->json($chartData);
        } catch (NotFoundHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
