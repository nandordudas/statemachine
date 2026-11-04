<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Exception\StateMachineException;
use Throwable;

/**
 * Outcome of {@see Machine::tryTransition()}: either the transition happened, or it was refused up front
 * (unknown event, not allowed from the current state, or vetoed by guards) and nothing changed.
 */
final readonly class TransitionResult
{
    /**
     * @param (StateMachineException&Throwable)|null $failure why the event was refused; null on success
     */
    private function __construct(
        public string $state,
        public (StateMachineException&Throwable)|null $failure,
    ) {}

    public static function succeeded(string $state): self
    {
        return new self($state, null);
    }

    public static function refused(string $state, StateMachineException&Throwable $failure): self
    {
        return new self($state, $failure);
    }

    public function ok(): bool
    {
        return $this->failure === null;
    }
}
