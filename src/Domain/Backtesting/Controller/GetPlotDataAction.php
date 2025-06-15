<?php

namespace Stochastix\Domain\Backtesting\Controller;

use Stochastix\Domain\Data\Exception\StorageException;
use Stochastix\Domain\Data\Service\MetricStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/plots/{metric}/{runId}', name: 'stochastix_api_plots_get', methods: ['GET'])]
class GetPlotDataAction extends AbstractController
{
    public function __construct(
        private readonly MetricStorage $metricStorage,
        #[Autowire('%kernel.project_dir%/data/backtests')]
        private readonly string $backtestStoragePath
    ) {
    }

    public function __invoke(string $runId, string $metric): JsonResponse
    {
        try {
            $filePath = $this->metricStorage->getFilePath($this->backtestStoragePath, $runId);

            // Read the entire contents of the metric file.
            $metricFileData = $this->metricStorage->read($filePath);

            // The data is structured as: [metricName => [seriesName => [dataPoints]]]
            // e.g. ['equity' => ['value' => [ ... ]]]
            $plotData = $metricFileData['data'][$metric]['value'] ?? null;

            if ($plotData === null) {
                throw new NotFoundHttpException("Metric plot data for '{$metric}' not found in run '{$runId}'.");
            }

            // The data is already in the correct format: [{'time': ..., 'value': ...}]
            return $this->json(['data' => $plotData]);
        } catch (StorageException $e) {
            // Catches file not found errors from the storage service
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (NotFoundHttpException $e) {
            return $this->json(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }
    }
}
