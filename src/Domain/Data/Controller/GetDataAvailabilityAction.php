<?php

namespace Stochastix\Domain\Data\Controller;

use Stochastix\Domain\Data\Service\DataAvailabilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
class GetDataAvailabilityAction extends AbstractController
{
    public function __construct(
        private readonly DataAvailabilityService $dataAvailabilityService
    ) {
    }

    #[Route('/api/data-availability', name: 'stochastix_api_data_availability', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $manifest = $this->dataAvailabilityService->getManifest();

        return $this->json($manifest);
    }
}
