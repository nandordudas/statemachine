<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

use BackedEnum;
use Dudas\StateMachine\Exception\StateMachineException;

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
}
