<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Closure;
use Dudas\StateMachine\Contracts\Hook;

final readonly class ClosureHook implements Hook
{
    /**
     * @param Closure(TransitionContext): mixed $callback
     */
    public function __construct(
        private Closure $callback,
    ) {}

    public static function wrap(Hook|callable $hook): Hook
    {
        return $hook instanceof Hook ? $hook : new self($hook(...));
    }

    #[\Override]
    public function handle(TransitionContext $context): void
    {
        ($this->callback)($context);
    }
}
