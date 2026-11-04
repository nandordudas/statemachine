<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use Dudas\StateMachine\Exception\UnknownEventException;

/**
 * Why an event was refused, before anything changed. The exception is only built when someone needs it
 * (send() throws it, tryTransition() returns it), so a refused sendIfAllowed() does not pay for a stack trace.
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

    public function exception(): UnknownEventException|TransitionNotAllowedException
    {
        return match ($this->reason) {
            self::UNKNOWN_EVENT => UnknownEventException::named($this->event),
            self::GUARD_REJECTED => GuardRejectedException::forEvent($this->event, $this->from),
            self::NOT_ALLOWED => TransitionNotAllowedException::fromState($this->event, $this->from),
        };
    }
}
