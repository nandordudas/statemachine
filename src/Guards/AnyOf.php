<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Support;
use Dudas\StateMachine\TransitionContext;

/** Allows when at least one inner guard allows. Short-circuits on the first approval. */
final class AnyOf implements Guard
{
    /** @var Guard[] */
    private $guards;

    /**
     * @param Guard|callable(TransitionContext): bool ...$guards
     */
    public function __construct(...$guards)
    {
        $this->guards = array_map([ClosureGuard::class, 'wrap'], array_values($guards));
    }

    public function allows(TransitionContext $context): bool
    {
        return Support::any($this->guards, static function (Guard $guard) use ($context): bool {
            return $guard->allows($context);
        });
    }
}
