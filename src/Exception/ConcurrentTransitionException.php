<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

/**
 * The state changed between selecting a transition and applying it (another writer, or a `before` hook
 * that sent another event), or the machine was re-entered where that could only race with the running
 * transition. The stale transition was NOT applied.
 *
 * `$retryable` tells a race from a bug: after a lost race or a busy machine, reload the entity and send the event
 * again; a send from a guard or a `before`/`onExit` hook is a programming error that a retry cannot fix.
 */
final class ConcurrentTransitionException extends LogicException implements StateMachineException
{
    private function __construct(
        string $message,
        public readonly bool $retryable,
    ) {
        parent::__construct($message);
    }

    public static function forEvent(string $event, string $expected, string $actual): self
    {
        return new self(
            sprintf(
                'Event "%s" was selected in state "%s" but the machine is now in "%s".',
                $event,
                $expected,
                $actual,
            ),
            true,
        );
    }

    public static function nestedSend(string $event): self
    {
        return new self(
            sprintf(
                'Event "%s" was sent from a `before`/`onExit` hook; send events from `onEnter`/`after` hooks instead.',
                $event,
            ),
            false,
        );
    }

    public static function busy(string $event): self
    {
        return new self(
            sprintf(
                'Event "%s" was sent while another caller (fiber) is still applying a transition on this machine.',
                $event,
            ),
            true,
        );
    }

    public static function sendFromGuard(string $event): self
    {
        return new self(
            sprintf('Event "%s" was sent from a guard; guards must be free of side effects.', $event),
            false,
        );
    }

    public static function lostRace(string $expected, string $state): self
    {
        return new self(
            sprintf('Could not move from "%s" to "%s": the stored state changed concurrently.', $expected, $state),
            true,
        );
    }
}
