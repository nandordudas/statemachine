<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * One reason why an event cannot be taken right now: something to show a user ("Only approvers can approve")
 * or to branch on (`$code`). {@see Machine::blockers()} lists them; an empty list means the event is allowed.
 */
final readonly class Blocker
{
    /** The event is not part of the definition. */
    public const string UNKNOWN_EVENT = 'unknown_event';

    /** No transition for the event leaves the current state. */
    public const string NOT_ALLOWED = 'not_allowed';

    /** A guard said no without explaining why (see {@see because()} and transition `meta['blocked']`). */
    public const string GUARD_REJECTED = 'guard_rejected';

    /**
     * @param array<string, mixed> $parameters details for the message, e.g. a required role or amount
     */
    public function __construct(
        public string $code,
        public string $message,
        public array $parameters = [],
    ) {}
}
