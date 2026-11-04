<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

use BackedEnum;
use Dudas\StateMachine\Exception\StateMachineException;
use Dudas\StateMachine\TransitionResult;

interface StateMachine
{
    public string $state { get; }

    public function is(string|BackedEnum ...$states): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function can(string|BackedEnum $event, array $payload = []): bool;

    /**
     * @param array<string, mixed> $payload
     * @return list<string> events that could be sent right now
     */
    public function availableEvents(array $payload = []): array;

    /**
     * @param array<string, mixed> $payload
     * @throws StateMachineException when the event is unknown or cannot be taken
     */
    public function send(string|BackedEnum $event, array $payload = []): static;

    /**
     * Like send(), but returns false instead of throwing when the event is unknown, not allowed
     * from the current state, or vetoed by guards. Hook and concurrency failures still throw.
     *
     * @param array<string, mixed> $payload
     */
    public function sendIfAllowed(string|BackedEnum $event, array $payload = []): bool;

    /**
     * Like sendIfAllowed(), but says why an event was refused.
     *
     * @param array<string, mixed> $payload
     */
    public function tryTransition(string|BackedEnum $event, array $payload = []): TransitionResult;

    /**
     * Sends several events in order; an item is an event or `[event, payload]`. Stops at the first failure.
     *
     * @param iterable<string|BackedEnum|array{0: string|BackedEnum, 1?: array<string, mixed>}> $events
     */
    public function sendAll(iterable $events): static;
}
