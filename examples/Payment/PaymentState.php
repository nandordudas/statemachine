<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Payment;

enum PaymentState: string
{
    case Pending = 'pending';
    case Authorized = 'authorized';
    case Captured = 'captured';
    case Declined = 'declined';
    case Refunded = 'refunded';
    case Voided = 'voided';
}
