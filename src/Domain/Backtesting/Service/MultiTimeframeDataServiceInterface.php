<?php

namespace Stochastix\Domain\Backtesting\Service;

use Ds\Vector;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

interface MultiTimeframeDataServiceInterface
{
    /**
     * @return array<string, Vector>
     */
    public function loadAndResample(string $symbol, string $exchangeId, Vector $primaryTimestamps, TimeframeEnum $secondaryTimeframe): array;
}
