<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Exception\StateMachineException;

/**
 * Outcome of {@see Machine::tryTransition()}: either the transition happened, or it was refused up front
 * (unknown event, not allowed from the current state, or vetoed by guards) and nothing changed.
 */
final class TransitionResult
{
    /** @var string @readonly */
    public $state;

    /** @var StateMachineException|null @readonly why the event was refused; null on success */
    public $failure;

    private function __construct(string $state, ?StateMachineException $failure)
    {
        $this->state = $state;
        $this->failure = $failure;
    }

    public static function succeeded(string $state): self
    {
        return new self($state, null);
    }

    public static function refused(string $state, StateMachineException $failure): self
    {
        return new self($state, $failure);
    }

    public function ok(): bool
    {
        return $this->failure === null;
    }
}
