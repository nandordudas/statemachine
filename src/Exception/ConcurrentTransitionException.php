<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

/**
 * The state changed between selecting a transition and applying it, or a hook sent a nested event.
 * The stale transition was NOT applied.
 *
 * `$retryable` tells a race from a bug: after a lost race, reload the entity and send the event again; a send from
 * a `before`/`onExit` hook is a programming error that a retry cannot fix.
 */
final class ConcurrentTransitionException extends LogicException implements StateMachineException
{
    /** @var bool @readonly */
    public $retryable;

    private function __construct(string $message, bool $retryable)
    {
        parent::__construct($message);

        $this->retryable = $retryable;
    }

    public static function forEvent(string $event, string $expected, string $actual): self
    {
        return new self(
            sprintf(
                'Event "%s" was selected in state "%s" but the machine is now in "%s".',
                $event,
                $expected,
                $actual
            ),
            true
        );
    }

    public static function nestedSend(string $event): self
    {
        return new self(
            sprintf(
                'Event "%s" was sent from a `before`/`onExit` hook; send events from `onEnter`/`after` hooks instead.',
                $event
            ),
            false
        );
    }

    public static function lostRace(string $expected, string $state): self
    {
        return new self(
            sprintf('Could not move from "%s" to "%s": the stored state changed concurrently.', $expected, $state),
            true
        );
    }
}
