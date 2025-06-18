<?php

namespace Stochastix\Domain\Data\Controller;

use Psr\Cache\CacheItemPoolInterface;
use Psr\Cache\InvalidArgumentException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/data/download/{jobId}', name: 'stochastix_api_data_cancel_download', methods: ['DELETE'])]
class CancelDownloadAction extends AbstractController
{
    public function __construct(
        #[Autowire(service: 'stochastix.download.cancel.cache')]
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * @throws InvalidArgumentException
     */
    public function __invoke(string $jobId): JsonResponse
    {
        $cacheKey = 'download.cancel.' . $jobId;
        $item = $this->cache->getItem($cacheKey);

        $item->set(true);
        $item->expiresAfter(3600);
        $this->cache->save($item);

        return $this->json(
            ['status' => 'cancellation_requested', 'jobId' => $jobId],
            Response::HTTP_ACCEPTED
        );
    }
}
