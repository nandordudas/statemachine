<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * Why an event was refused, before anything changed. It carries the reasons out of the store's transaction; the
 * machine builds the exception from it afterwards (send() throws it, tryTransition() returns it).
 *
 * @internal
 */
final readonly class Refusal
{
    public const string UNKNOWN_EVENT = 'unknown-event';

    public const string NOT_ALLOWED = 'not-allowed';

    public const string GUARD_REJECTED = 'guard-rejected';

    /**
     * @param self::UNKNOWN_EVENT|self::NOT_ALLOWED|self::GUARD_REJECTED $reason
     * @param array<string, mixed> $payload the event's payload
     * @param list<Blocker> $blockers why the guards said no, collected in the same pass (and under the same lock)
     *                                that refused the event
     */
    public function __construct(
        public string $event,
        public string $from,
        public string $reason,
        public array $payload = [],
        public array $blockers = [],
    ) {}
}
