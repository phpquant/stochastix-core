<?php

namespace Stochastix\Domain\Common\Model;

use Ds\Vector;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;

class Series implements \ArrayAccess, \Countable
{
    protected Vector $values;
    protected ?BacktestCursor $cursor;

    public function __construct(iterable $initialValues = [], ?BacktestCursor $cursor = null)
    {
        $mapped = array_map(
            static fn ($v) => $v === false ? null : $v,
            is_array($initialValues) ? $initialValues : iterator_to_array($initialValues)
        );
        $this->values = new Vector($mapped);
        $this->cursor = $cursor;
    }

    public function setCursor(BacktestCursor $cursor): void
    {
        $this->cursor = $cursor;
    }

    protected function getCurrentIndex(): int
    {
        return $this->cursor->currentIndex ?? ($this->values->count() - 1);
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!is_int($offset) || $offset < 0) {
            return false;
        }

        $actualIndex = $this->getCurrentIndex() - $offset;

        return $actualIndex >= 0 && $actualIndex < $this->values->count();
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_int($offset) || $offset < 0) {
            return null;
        }

        $actualIndex = $this->getCurrentIndex() - $offset;

        if ($actualIndex < 0 || $actualIndex >= $this->values->count()) {
            return null;
        }

        return $this->values->get($actualIndex);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new \LogicException('Series data is managed internally and is read-only via ArrayAccess.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new \LogicException('Series data is managed internally and is read-only via ArrayAccess.');
    }

    public function count(): int
    {
        return $this->values->count();
    }

    public function current(): mixed
    {
        return $this[0];
    }

    public function previous(): mixed
    {
        return $this[1];
    }

    public function toArray(): array
    {
        return $this->values->toArray();
    }

    public function getVector(): Vector
    {
        return $this->values;
    }

    public function crossesOver(Series $otherSeries): bool
    {
        $aCurrent = $this[0];
        $aPrevious = $this[1];
        $bCurrent = $otherSeries[0];
        $bPrevious = $otherSeries[1];

        if ($aCurrent === null || $aPrevious === null || $bCurrent === null || $bPrevious === null) {
            return false;
        }

        return $aPrevious <= $bPrevious && $aCurrent > $bCurrent;
    }

    public function crossesUnder(Series $otherSeries): bool
    {
        $aCurrent = $this[0];
        $aPrevious = $this[1];
        $bCurrent = $otherSeries[0];
        $bPrevious = $otherSeries[1];

        if ($aCurrent === null || $aPrevious === null || $bCurrent === null || $bPrevious === null) {
            return false;
        }

        return $aPrevious >= $bPrevious && $aCurrent < $bCurrent;
    }
}
