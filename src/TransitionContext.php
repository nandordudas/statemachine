<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * What a guard or hook gets to see about the transition being attempted.
 */
final readonly class TransitionContext
{
    /**
     * @param array<string, mixed> $payload data passed along with the event
     */
    public function __construct(
        public string $event,
        public string $from,
        public string $to,
        public array $payload = [],
    ) {}
}
