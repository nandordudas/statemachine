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

    public static function wrap(Guard|callable $guard): Guard
    {
        return $guard instanceof Guard ? $guard : new self($guard(...));
    }

    public function allows(TransitionContext $context): bool
    {
        return ($this->callback)($context);
    }
}
