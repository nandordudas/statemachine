<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Allows when two payload entries are strictly identical (both missing counts as equal). */
final readonly class PayloadsEqual implements Guard
{
    public function __construct(
        private string $a,
        private string $b,
    ) {}

    public function allows(TransitionContext $context): bool
    {
        return ($context->payload[$this->a] ?? null) === ($context->payload[$this->b] ?? null);
    }
}
