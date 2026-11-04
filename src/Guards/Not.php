<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Inverts a guard. */
final readonly class Not implements Guard
{
    private Guard $guard;

    /**
     * @param Guard|callable(TransitionContext): bool $guard
     */
    public function __construct(Guard|callable $guard)
    {
        $this->guard = ClosureGuard::wrap($guard);
    }

    public function allows(TransitionContext $context): bool
    {
        return !$this->guard->allows($context);
    }
}
