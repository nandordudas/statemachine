<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * Why an event was refused, before anything changed. The machine builds the exception only when someone needs
 * it (send() throws it, tryTransition() returns it), so a refused sendIfAllowed() does not pay for a stack trace.
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
     */
    public function __construct(
        public string $event,
        public string $from,
        public string $reason,
    ) {}
}
