<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Allows when two payload entries are strictly identical (both missing counts as equal). */
final class PayloadsEqual implements Guard
{
    /** @var string */
    private $a;

    /** @var string */
    private $b;

    public function __construct(string $a, string $b)
    {
        $this->a = $a;
        $this->b = $b;
    }

    public function allows(TransitionContext $context): bool
    {
        return ($context->payload[$this->a] ?? null) === ($context->payload[$this->b] ?? null);
    }
}
