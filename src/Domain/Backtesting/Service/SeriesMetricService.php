<?php

namespace Stochastix\Domain\Backtesting\Service;

use Stochastix\Domain\Backtesting\Service\Metric\DependentSeriesMetricInterface;
use Stochastix\Domain\Backtesting\Service\Metric\SeriesMetricInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final readonly class SeriesMetricService implements SeriesMetricServiceInterface
{
    /** @var SeriesMetricInterface[] */
    private array $metrics;

    public function __construct(
        #[AutowireIterator(SeriesMetricInterface::class)]
        iterable $metrics
    ) {
        $newMetrics = [];

        foreach ($metrics as $metric) {
            $newMetrics[$metric::class] = $metric;
        }

        $this->metrics = $newMetrics;
    }

    public function calculate(array $backtestResults): array
    {
        $executionOrder = $this->resolveExecutionOrder();
        $calculatedResults = [];

        foreach ($executionOrder as $metricClass) {
            $metric = $this->metrics[$metricClass];

            if ($metric instanceof DependentSeriesMetricInterface) {
                $dependencyResults = [];
                foreach ($metric->getDependencies() as $dependencyClass) {
                    if (!isset($calculatedResults[$dependencyClass])) {
                        throw new \LogicException("Unmet dependency: {$dependencyClass} was not calculated before {$metricClass}");
                    }
                    $dependencyResults[$dependencyClass] = $calculatedResults[$dependencyClass];
                }
                $metric->setDependencyResults($dependencyResults);
            }

            $metric->calculate($backtestResults);

            // Cache the result for other metrics that might depend on it
            $calculatedResults[$metricClass] = ['values' => $metric->getValues()];
        }

        $finalData = [];
        foreach ($calculatedResults as $metricClass => $result) {
            $metric = $this->metrics[$metricClass];
            $finalData[$metric->getName()] = ['value' => $result['values']];
        }

        return $finalData;
    }

    private function resolveExecutionOrder(): array
    {
        $nodes = array_keys($this->metrics);
        $edges = [];
        $inDegree = array_fill_keys($nodes, 0);

        foreach ($this->metrics as $metricClass => $metric) {
            if ($metric instanceof DependentSeriesMetricInterface) {
                foreach ($metric->getDependencies() as $dependencyClass) {
                    $edges[$dependencyClass][] = $metricClass;
                    ++$inDegree[$metricClass];
                }
            }
        }

        $queue = new \SplQueue();
        foreach ($inDegree as $node => $degree) {
            if ($degree === 0) {
                $queue->enqueue($node);
            }
        }

        $sorted = [];
        while (!$queue->isEmpty()) {
            $node = $queue->dequeue();
            $sorted[] = $node;
            foreach ($edges[$node] ?? [] as $neighbor) {
                --$inDegree[$neighbor];
                if ($inDegree[$neighbor] === 0) {
                    $queue->enqueue($neighbor);
                }
            }
        }

        if (count($sorted) !== count($nodes)) {
            throw new \RuntimeException('A circular dependency was detected in the Series Metrics.');
        }

        return $sorted;
    }
}
