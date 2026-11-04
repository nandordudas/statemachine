<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

use Dudas\StateMachine\Blocker;
use Dudas\StateMachine\TransitionContext;

/**
 * A guard that can say why it refuses. Like {@see Guard::allows()}, it must be free of side effects.
 */
interface ExplainingGuard extends Guard
{
    /**
     * @return list<Blocker> why the transition is refused; empty exactly when {@see allows()} returns true
     */
    public function blockers(TransitionContext $context): array;
}
