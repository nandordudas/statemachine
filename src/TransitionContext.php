<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * What a guard or hook gets to see about the transition being attempted.
 */
final class TransitionContext
{
    /** @var string @readonly */
    public $event;

    /** @var string @readonly */
    public $from;

    /** @var string @readonly */
    public $to;

    /** @var array<string, mixed> @readonly data passed along with the event */
    public $payload;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(string $event, string $from, string $to, array $payload = [])
    {
        $this->event = $event;
        $this->from = $from;
        $this->to = $to;
        $this->payload = $payload;
    }
}
