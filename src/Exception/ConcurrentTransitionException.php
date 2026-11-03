<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

/**
 * The state changed between selecting a transition and applying it, e.g. a `before` hook
 * sent another event. The stale transition was not applied.
 */
final class ConcurrentTransitionException extends LogicException implements StateMachineException
{
    public static function forEvent(string $event, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Event "%s" was selected in state "%s" but the machine is now in "%s".',
            $event,
            $expected,
            $actual,
        ));
    }
}
