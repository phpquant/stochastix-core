<?php

namespace Stochastix\Domain\Backtesting\Controller;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Backtesting\Dto\LaunchBacktestRequestDto;
use Stochastix\Domain\Backtesting\Message\RunBacktestMessage;
use Stochastix\Domain\Backtesting\Repository\BacktestResultRepositoryInterface;
use Stochastix\Domain\Backtesting\Service\ApiBacktestConfigurationFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/backtests', name: 'stochastix_api_backtests_launch', methods: ['POST'])]
class LaunchBacktestAction extends AbstractController
{
    public function __construct(
        private readonly ApiBacktestConfigurationFactory $configurationFactory,
        private readonly MessageBusInterface $messageBus,
        private readonly BacktestResultRepositoryInterface $resultRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    public function __invoke(
        #[MapRequestPayload]
        LaunchBacktestRequestDto $requestDto
    ): JsonResponse {
        try {
            $backtestConfiguration = $this->configurationFactory->create($requestDto);

            // 1. Generate a descriptive, unique ID using the repository
            $backtestRunId = $this->resultRepository->generateRunId($requestDto->strategyAlias);

            // 2. Dispatch the message
            $message = new RunBacktestMessage($backtestRunId, $backtestConfiguration);
            $this->messageBus->dispatch($message);

            $this->logger->info('Backtest has been queued.', [
                'backtestRunId' => $backtestRunId,
                'strategyAlias' => $requestDto->strategyAlias,
            ]);

            // 3. Return the generated ID
            return $this->json(
                [
                    'status' => 'queued',
                    'backtestRunId' => $backtestRunId,
                ],
                Response::HTTP_ACCEPTED
            );
        } catch (\InvalidArgumentException $e) {
            $this->logger->warning('Invalid backtest launch request: {message}', ['message' => $e->getMessage()]);

            return $this->json(['error' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }
}
