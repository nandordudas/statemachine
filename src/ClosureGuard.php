<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Closure;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

final class ClosureGuard implements Guard
{
    /** @var Closure */
    private $callback;

    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    /**
     * @param Guard|callable(TransitionContext): bool $guard
     */
    public static function wrap($guard): Guard
    {
        if ($guard instanceof Guard) {
            return $guard;
        }

        if (!is_callable($guard)) {
            throw new InvalidDefinitionException('A guard must be a Guard or a callable.');
        }

        return new self(Closure::fromCallable($guard));
    }

    public function allows(TransitionContext $context): bool
    {
        return (bool) ($this->callback)($context);
    }
}
