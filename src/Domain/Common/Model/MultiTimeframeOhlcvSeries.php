<?php

namespace Stochastix\Domain\Common\Model;

use Ds\Map;
use Ds\Vector;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\TimeframeEnum;

/**
 * A composite series for multi-timeframe strategies.
 *
 * It extends OhlcvSeries to act as the primary series, allowing direct access
 * like $bars->close. It also implements ArrayAccess to provide access to
 * secondary timeframe series, e.g., $bars[TimeframeEnum::H4]->close.
 */
final readonly class MultiTimeframeOhlcvSeries extends OhlcvSeries implements \ArrayAccess
{
    /** @var Map<string, OhlcvSeries> */
    private Map $secondarySeries;

    /**
     * @param array<string, Vector> $primaryMarketData   the raw data for the primary timeframe
     * @param Map<string, array>    $secondaryDataframes a map of raw data for all secondary timeframes
     * @param BacktestCursor        $cursor              the shared backtest cursor
     */
    public function __construct(array $primaryMarketData, Map $secondaryDataframes, BacktestCursor $cursor)
    {
        // Initialize the parent with the primary series data.
        parent::__construct($primaryMarketData, $cursor);

        $this->secondarySeries = new Map();
        foreach ($secondaryDataframes as $timeframeValue => $marketData) {
            $this->secondarySeries->put($timeframeValue, new OhlcvSeries($marketData, $cursor));
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!$offset instanceof TimeframeEnum) {
            return false;
        }

        return $this->secondarySeries->hasKey($offset->value);
    }

    public function offsetGet(mixed $offset): ?OhlcvSeries
    {
        if (!$offset instanceof TimeframeEnum) {
            return null;
        }

        return $this->secondarySeries->get($offset->value, null);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Timeframe series are read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Timeframe series are read-only.');
    }
}
