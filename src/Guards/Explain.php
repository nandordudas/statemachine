<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\Blocker;
use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\ExplainingGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/**
 * @internal
 */
final class Explain
{
    /**
     * Why `$guard` refuses: its own reasons when it can explain itself, a generic one otherwise.
     *
     * @return list<Blocker> empty when the guard allows
     */
    public static function blockers(Guard $guard, TransitionContext $context): array
    {
        if ($guard instanceof ExplainingGuard) {
            return $guard->blockers($context);
        }

        return $guard->allows($context) ? [] : [self::generic($guard)];
    }

    public static function generic(Guard $guard): Blocker
    {
        return new Blocker(Blocker::GUARD_REJECTED, 'A guard rejected the transition.', [
            'guard' => $guard instanceof ClosureGuard ? 'closure' : $guard::class,
        ]);
    }

    private function __construct() {}
}
