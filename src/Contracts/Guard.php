<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

use Dudas\StateMachine\TransitionContext;

interface Guard
{
    /**
     * Whether the transition may be taken. Must be free of side effects:
     * it is also evaluated by `can()` and `availableEvents()`.
     */
    public function allows(TransitionContext $context): bool;
}
