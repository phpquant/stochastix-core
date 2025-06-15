<?php

namespace Stochastix\Domain\Strategy;

use Stochastix\Domain\Common\Model\MultiTimeframeOhlcvSeries;
use Stochastix\Domain\Plot\PlotDefinition;
use Stochastix\Domain\Strategy\Model\StrategyContext;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag]
interface StrategyInterface
{
    public function configure(array $runtimeParameters): void;

    public function initialize(StrategyContext $context): void;

    public function onBar(MultiTimeframeOhlcvSeries $bars): void;

    /**
     * @return array<string, PlotDefinition>
     */
    public function getPlotDefinitions(): array;
}
