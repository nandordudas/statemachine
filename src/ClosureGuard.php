<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Closure;
use Dudas\StateMachine\Contracts\Guard;

final readonly class ClosureGuard implements Guard
{
    /**
     * @param Closure(TransitionContext): bool $callback
     */
    public function __construct(private Closure $callback) {}

    public function allows(TransitionContext $context): bool
    {
        return ($this->callback)($context);
    }
}
