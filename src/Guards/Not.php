<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Inverts a guard. */
final class Not implements Guard
{
    /** @var Guard */
    private $guard;

    /**
     * @param Guard|callable(TransitionContext): bool $guard
     */
    public function __construct($guard)
    {
        $this->guard = ClosureGuard::wrap($guard);
    }

    public function allows(TransitionContext $context): bool
    {
        return !$this->guard->allows($context);
    }
}
