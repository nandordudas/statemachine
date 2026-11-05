<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * The transition a send() has started (its `before` hooks are running or ran), so it can be completed
 * or compensated once the store's transaction has returned.
 *
 * @internal
 */
final class Attempt
{
    public ?Transition $transition = null;

    public ?TransitionContext $context = null;

    public function clear(): void
    {
        $this->transition = null;
        $this->context = null;
    }
}
