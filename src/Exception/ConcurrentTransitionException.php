<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

/**
 * The state changed between selecting a transition and applying it, or a hook sent a nested event.
 * The stale transition was not applied.
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

    public static function nestedSend(string $event): self
    {
        return new self(sprintf(
            'Event "%s" was sent from a `before`/`onExit` hook; send events from `onEnter`/`after` hooks instead.',
            $event,
        ));
    }

    public static function lostRace(string $expected, string $state): self
    {
        return new self(sprintf(
            'Could not move from "%s" to "%s": the stored state changed concurrently.',
            $expected,
            $state,
        ));
    }
}
