<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\ExplainingGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Inverts a guard. */
final readonly class Not implements ExplainingGuard
{
    private Guard $guard;

    /**
     * @param Guard|callable(TransitionContext): bool $guard
     */
    public function __construct(Guard|callable $guard)
    {
        $this->guard = ClosureGuard::wrap($guard);
    }

    #[\Override]
    public function allows(TransitionContext $context): bool
    {
        return !$this->guard->allows($context);
    }

    /**
     * The inner guard allowed, which gives no reason to show: wrap the Not in {@see Because} for a message.
     */
    #[\Override]
    public function blockers(TransitionContext $context): array
    {
        return $this->allows($context) ? [] : [Explain::generic($this)];
    }
}
