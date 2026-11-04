<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\StateMachineException;
use Dudas\StateMachine\Exception\UnknownEventException;
use Throwable;

/**
 * Outcome of {@see Machine::tryTransition()}: either the transition happened, or it was refused up front
 * (unknown event, not allowed from the current state, or vetoed by guards) and nothing changed.
 *
 * Sent from an `onEnter`/`after` hook, an event is queued and runs once the current transition is complete;
 * its result is then `queued` (not refused so far, not applied yet).
 */
final readonly class TransitionResult
{
    /**
     * @param string $state the state this transition entered (on success) or the unchanged state (when refused);
     *                      events chained from hooks may have moved the machine on since, see `$machine->state`
     * @param (StateMachineException&Throwable)|null $failure why the event was refused; null otherwise
     * @param TransitionContext|null $context the applied transition; null when refused or queued
     */
    private function __construct(
        public string $state,
        public (StateMachineException&Throwable)|null $failure,
        public ?TransitionContext $context = null,
        public bool $queued = false,
    ) {}

    public static function succeeded(string $state, ?TransitionContext $context = null): self
    {
        return new self($state, null, $context);
    }

    public static function refused(string $state, StateMachineException&Throwable $failure): self
    {
        return new self($state, $failure);
    }

    public static function queued(string $state): self
    {
        return new self($state, null, null, true);
    }

    public function ok(): bool
    {
        return $this->failure === null;
    }

    /**
     * Why the event was refused, as reasons to show; empty when it was not.
     *
     * @return list<Blocker>
     */
    public function blockers(): array
    {
        return match (true) {
            $this->failure === null => [],
            $this->failure instanceof GuardRejectedException => $this->failure->blockers(),
            $this->failure instanceof UnknownEventException => [
                new Blocker(Blocker::UNKNOWN_EVENT, $this->failure->getMessage()),
            ],
            default => [new Blocker(Blocker::NOT_ALLOWED, $this->failure->getMessage())],
        };
    }
}
