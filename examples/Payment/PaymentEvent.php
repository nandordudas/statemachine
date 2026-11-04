<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Payment;

enum PaymentEvent: string
{
    case Authorize = 'AUTHORIZE';
    case Capture = 'CAPTURE';
    case Void = 'VOID';
    case Refund = 'REFUND';
}
