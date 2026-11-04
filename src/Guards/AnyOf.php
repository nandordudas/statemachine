<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\ExplainingGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Allows when at least one inner guard allows. Short-circuits on the first approval. */
final readonly class AnyOf implements ExplainingGuard
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

    /**
     * Nothing when one inner guard allows; otherwise the reasons of all of them.
     */
    public function blockers(TransitionContext $context): array
    {
        $blockers = [];

        foreach ($this->guards as $guard) {
            $reasons = Explain::blockers($guard, $context);

            if ($reasons === []) {
                return [];
            }

            array_push($blockers, ...$reasons);
        }

        return $blockers;
    }
}
