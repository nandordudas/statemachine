<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Allows when every inner guard allows. Short-circuits on the first veto. */
final readonly class AllOf implements Guard
{
    /** @var list<Guard> */
    private array $guards;

    /**
     * @param Guard|callable(TransitionContext): bool ...$guards
     */
    public function __construct(Guard|callable ...$guards)
    {
        $this->guards = array_map(ClosureGuard::wrap(...), array_values($guards));
    }

    public function allows(TransitionContext $context): bool
    {
        return array_all($this->guards, static fn(Guard $guard): bool => $guard->allows($context));
    }
}
