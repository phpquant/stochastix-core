<?php

namespace Stochastix\Domain\Backtesting\MessageHandler;

use Psr\Log\LoggerInterface;
use Stochastix\Domain\Backtesting\Message\RunBacktestMessage;
use Stochastix\Domain\Backtesting\Service\Backtester;
use Stochastix\Domain\Backtesting\Service\BacktestResultSaver;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class RunBacktestMessageHandler
{
    public function __construct(
        private Backtester $backtester,
        private HubInterface $mercureHub,
        private BacktestResultSaver $resultSaver,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RunBacktestMessage $message): void
    {
        $runId = $message->backtestRunId;
        $topic = sprintf('/backtests/%s/progress', $runId);

        $this->logger->info('Handler started for backtest run: {runId}', ['runId' => $runId]);

        try {
            $this->publishUpdate($topic, [
                'status' => 'running',
                'progress' => 0,
                'messageKey' => 'results.page.progress.messageInitializing',
            ]);

            $lastUpdateTime = 0.0;
            $progressCallback = function (int $processed, int $total) use ($topic, &$lastUpdateTime) {
                $currentTime = microtime(true);
                if (($currentTime - $lastUpdateTime) >= 1.0 || $processed === $total) {
                    $lastUpdateTime = $currentTime;
                    $percentage = $total > 0 ? round(($processed / $total) * 100) : 0;
                    $this->publishUpdate($topic, [
                        'status' => 'running',
                        'progress' => $percentage,
                        'messageKey' => 'results.page.progress.messageProcessing',
                        'messageParams' => ['processed' => $processed, 'total' => $total],
                    ]);
                }
            };

            $results = $this->backtester->run($message->configuration, $progressCallback);

            $this->resultSaver->save($runId, $results);

            $this->publishUpdate($topic, [
                'status' => 'completed', 'progress' => 100,
                'messageKey' => 'results.page.progress.messageCompleted',
                'resultsUrl' => sprintf('/api/backtests/%s', $runId),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Backtest run {runId} failed: {message}', [
                'runId' => $runId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->publishUpdate($topic, [
                'status' => 'failed',
                'errorKey' => 'results.page.progress.alertErrorDefault',
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function publishUpdate(string $topic, array $data): void
    {
        $update = new Update($topic, json_encode($data, JSON_THROW_ON_ERROR));
        $this->mercureHub->publish($update);
    }
}
