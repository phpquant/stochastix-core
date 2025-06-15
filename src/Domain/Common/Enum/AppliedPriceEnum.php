<?php

namespace Stochastix\Domain\Common\Enum;

enum AppliedPriceEnum: string
{
    case Open = 'open';
    case High = 'high';
    case Low = 'low';
    case Close = 'close';
}
