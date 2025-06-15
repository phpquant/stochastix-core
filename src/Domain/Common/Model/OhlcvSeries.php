<?php

namespace Stochastix\Domain\Common\Model;

use Ds\Vector;
use Stochastix\Domain\Backtesting\Model\BacktestCursor;
use Stochastix\Domain\Common\Enum\OhlcvEnum;

readonly class OhlcvSeries
{
    public private(set) Series $open;
    public private(set) Series $high;
    public private(set) Series $low;
    public private(set) Series $close;
    public private(set) Series $volume;
    public private(set) Series $timestamp;
    public private(set) Series $hlc3;

    public function __construct(array $marketData, private BacktestCursor $cursor)
    {
        $this->open = new Series($marketData[OhlcvEnum::Open->value] ?? [], $cursor);
        $this->high = new Series($marketData[OhlcvEnum::High->value] ?? [], $cursor);
        $this->low = new Series($marketData[OhlcvEnum::Low->value] ?? [], $cursor);
        $this->close = new Series($marketData[OhlcvEnum::Close->value] ?? [], $cursor);
        $this->volume = new Series($marketData[OhlcvEnum::Volume->value] ?? [], $cursor);
        $this->timestamp = new Series($marketData[OhlcvEnum::Timestamp->value] ?? [], $cursor);

        $this->calculateHlc3();
    }

    private function calculateHlc3(): void
    {
        $hlc3Values = new Vector();
        $count = $this->close->count();

        for ($i = 0; $i < $count; ++$i) {
            $h = $this->high->getVector()->get($i);
            $l = $this->low->getVector()->get($i);
            $c = $this->close->getVector()->get($i);

            if ($h === null || $l === null || $c === null) {
                $hlc3Values->push(null);
            } else {
                $sum = bcadd((string) $h, (string) $l);
                $sum = bcadd($sum, (string) $c);
                $hlc3 = bcdiv($sum, '3');
                $hlc3Values->push($hlc3);
            }
        }

        $this->hlc3 = new Series($hlc3Values, $this->cursor);
    }
}
