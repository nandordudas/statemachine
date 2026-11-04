<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Document;

use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/**
 * Reviewing needs the `approver` role, and nobody reviews their own work.
 * Reads `role`, `actor` and `author` from the event payload.
 */
final readonly class ApproverGuard implements Guard
{
    public function allows(TransitionContext $context): bool
    {
        $actor = $context->payload['actor'] ?? null;

        return (
            ($context->payload['role'] ?? null) === 'approver'
            && $actor !== null
            && $actor !== ($context->payload['author'] ?? null)
        );
    }
}
