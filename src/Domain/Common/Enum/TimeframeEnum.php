<?php

namespace Stochastix\Domain\Common\Enum;

enum TimeframeEnum: string
{
    case M1 = '1m';
    case M5 = '5m';
    case M15 = '15m';
    case M30 = '30m';
    case H1 = '1h';
    case H4 = '4h';
    case D1 = '1d';
    case W1 = '1w';
    case MN1 = '1M';
}
