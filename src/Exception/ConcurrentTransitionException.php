<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

/**
 * The state changed between selecting a transition and applying it (another writer, or a `before` hook
 * that sent another event), or the machine was re-entered where that could only race with the running
 * transition. The stale transition was not applied.
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

    public static function busy(string $event): self
    {
        return new self(sprintf(
            'Event "%s" was sent while another caller (fiber) is still applying a transition on this machine.',
            $event,
        ));
    }

    public static function sendFromGuard(string $event): self
    {
        return new self(sprintf(
            'Event "%s" was sent from a guard; guards must be free of side effects.',
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
