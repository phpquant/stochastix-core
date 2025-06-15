<?php

namespace Stochastix\Domain\Common\Model;

class MutableSeries extends Series
{
    private int $currentIndex = 0;

    public function __construct(iterable $initialValues = [])
    {
        parent::__construct($initialValues);
        if (!$this->values->isEmpty()) {
            $this->currentIndex = $this->values->count() - 1;
        }
    }

    public function setCurrentIndex(int $index): void
    {
        if ($index < 0 || $index >= $this->values->count()) {
            throw new \OutOfBoundsException("Current index {$index} is out of bounds for series of count " . $this->values->count());
        }
        $this->currentIndex = $index;
    }

    public function append(mixed $value): void
    {
        $this->values->push($value === false ? null : $value);
    }

    public function offsetExists(mixed $offset): bool
    {
        if (!is_int($offset) || $offset < 0) {
            return false;
        }

        $actualIndex = $this->currentIndex - $offset;

        return $actualIndex >= 0 && $actualIndex < $this->values->count();
    }

    public function offsetGet(mixed $offset): mixed
    {
        if (!is_int($offset) || $offset < 0) {
            return null;
        }

        $actualIndex = $this->currentIndex - $offset;

        if ($actualIndex < 0 || $actualIndex >= $this->values->count()) {
            return null;
        }

        return $this->values->get($actualIndex);
    }
}
