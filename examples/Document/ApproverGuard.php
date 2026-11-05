<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Document;

use Dudas\StateMachine\Blocker;
use Dudas\StateMachine\Contracts\ExplainingGuard;
use Dudas\StateMachine\TransitionContext;

/**
 * Reviewing needs the `approver` role, and nobody reviews their own work.
 * Reads `role`, `actor` and `author` from the event payload, and says which rule failed.
 */
final readonly class ApproverGuard implements ExplainingGuard
{
    #[\Override]
    public function allows(TransitionContext $context): bool
    {
        return $this->blockers($context) === [];
    }

    #[\Override]
    public function blockers(TransitionContext $context): array
    {
        $actor = $context->payload['actor'] ?? null;
        $blockers = [];

        if (($context->payload['role'] ?? null) !== 'approver' || $actor === null) {
            $blockers[] = new Blocker('not_approver', 'Only approvers can review documents.', ['role' => 'approver']);
        }

        if ($actor !== null && $actor === ($context->payload['author'] ?? null)) {
            $blockers[] = new Blocker('own_work', 'Authors cannot review their own documents.');
        }

        return $blockers;
    }
}
