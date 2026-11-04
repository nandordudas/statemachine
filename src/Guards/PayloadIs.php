<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/** Allows when `payload[$key]` is strictly identical to `$expected`. */
final class PayloadIs implements Guard
{
    /** @var string */
    private $key;

    /** @var mixed */
    private $expected;

    /**
     * @param mixed $expected
     */
    public function __construct(string $key, $expected)
    {
        $this->key = $key;
        $this->expected = $expected;
    }

    public function allows(TransitionContext $context): bool
    {
        return ($context->payload[$this->key] ?? null) === $this->expected;
    }
}
