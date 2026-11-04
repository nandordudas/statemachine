<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

use Dudas\StateMachine\Exception\StateMachineException;
use Dudas\StateMachine\TransitionResult;

interface StateMachine
{
    public function state(): string;

    public function is(string ...$states): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function can(string $event, array $payload = []): bool;

    /**
     * @param array<string, mixed> $payload
     * @return string[] events that could be sent right now
     */
    public function availableEvents(array $payload = []): array;

    /**
     * @param array<string, mixed> $payload
     * @return static
     * @throws StateMachineException when the event is unknown or cannot be taken
     */
    public function send(string $event, array $payload = []);

    /**
     * Sends several events in order; an item is an event or `[event, payload]`. Stops at the first failure.
     *
     * @param iterable<string|array{0: string, 1?: array<string, mixed>}> $events
     * @return static
     */
    public function sendAll(iterable $events);

    /**
     * Like send(), but returns false instead of throwing when the event is unknown, not allowed
     * from the current state, or vetoed by guards. Hook and concurrency failures still throw.
     *
     * @param array<string, mixed> $payload
     */
    public function sendIfAllowed(string $event, array $payload = []): bool;

    /**
     * Like sendIfAllowed(), but says why an event was refused.
     *
     * @param array<string, mixed> $payload
     */
    public function tryTransition(string $event, array $payload = []): TransitionResult;
}
