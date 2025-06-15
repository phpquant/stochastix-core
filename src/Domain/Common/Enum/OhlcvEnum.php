<?php

namespace Stochastix\Domain\Common\Enum;

enum OhlcvEnum: string
{
    case Timestamp = 'timestamp';
    case Open = 'open';
    case High = 'high';
    case Low = 'low';
    case Close = 'close';
    case Volume = 'volume';
}
