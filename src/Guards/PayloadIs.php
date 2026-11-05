<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use BackedEnum;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Allows when `payload[$key]` is strictly identical to `$expected` (a backed enum matches its value too). */
final readonly class PayloadIs implements Guard
{
    public function __construct(
        private string $key,
        private mixed $expected,
    ) {}

    #[\Override]
    public function allows(TransitionContext $context): bool
    {
        $actual = $context->payload[$this->key] ?? null;

        return (
            $actual === $this->expected
            || $this->expected instanceof BackedEnum
            && $actual === $this->expected->value
        );
    }
}
