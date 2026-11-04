<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Allows when at least one inner guard allows. Short-circuits on the first approval. */
final readonly class AnyOf implements Guard
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
        return array_any($this->guards, static fn(Guard $guard): bool => $guard->allows($context));
    }
}
