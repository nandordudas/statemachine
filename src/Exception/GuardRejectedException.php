<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

/**
 * The event is valid for the current state, but every matching transition's guard said no.
 */
final class GuardRejectedException extends TransitionNotAllowedException
{
    public static function forEvent(string $event, string $from): self
    {
        return new self($event, $from, sprintf('Guard rejected event "%s" in state "%s".', $event, $from));
    }
}
