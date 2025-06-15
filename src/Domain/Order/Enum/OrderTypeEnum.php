<?php

namespace Stochastix\Domain\Order\Enum;

enum OrderTypeEnum: string
{
    case Market = 'market';
    case Limit = 'limit';
    case Stop = 'stop';
    // case StopLimit = 'stop_limit'; // Reserved for future expansion
}
