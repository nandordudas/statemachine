<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Document;

enum DocumentEvent: string
{
    case Submit = 'SUBMIT';
    case Approve = 'APPROVE';
    case Reject = 'REJECT';
    case Archive = 'ARCHIVE';
}
