<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\OrderStatus;

/**
 * The `order_status.group` column. `None` is the group of an order that has no status yet.
 */
enum StatusGroup: string
{
    case None = '';
    case Incoming = 'active/incoming';
    case Serving = 'active/serving';
    case Served = 'served';
    case Inactive = 'inactive';
    case SentBack = 'sent-back';
    case Returned = 'returned';
}
