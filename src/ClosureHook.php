<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Closure;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

final class ClosureHook implements Hook
{
    /** @var Closure */
    private $callback;

    public function __construct(Closure $callback)
    {
        $this->callback = $callback;
    }

    /**
     * @param Hook|callable(TransitionContext): mixed $hook
     */
    public static function wrap($hook): Hook
    {
        if ($hook instanceof Hook) {
            return $hook;
        }

        if (!is_callable($hook)) {
            throw new InvalidDefinitionException('A hook must be a Hook or a callable.');
        }

        return new self(Closure::fromCallable($hook));
    }

    public function handle(TransitionContext $context): void
    {
        ($this->callback)($context);
    }
}
