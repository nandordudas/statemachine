<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Exception\UnknownStateException;

/**
 * What a guard or hook gets to see about the transition being attempted.
 */
final readonly class TransitionContext
{
    /**
     * @param array<string, mixed> $payload data passed along with the event
     * @param array<string, mixed> $meta the transition's metadata (labels, required roles, messages)
     */
    public function __construct(
        public string $event,
        public string $from,
        public string $to,
        public array $payload = [],
        public array $meta = [],
    ) {}

    /**
     * The source state as a case of `$enum`, for hooks and guards that work with enums:
     *
     *     $c->fromAs(DocState::class);   // DocState::Review
     *
     * @template T of BackedEnum
     * @param class-string<T> $enum
     * @return T
     * @throws UnknownStateException when the state is not a case of `$enum`
     */
    public function fromAs(string $enum): BackedEnum
    {
        return $enum::tryFrom($this->from) ?? throw UnknownStateException::notACase($this->from, $enum);
    }

    /**
     * The target state as a case of `$enum`.
     *
     * @template T of BackedEnum
     * @param class-string<T> $enum
     * @return T
     * @throws UnknownStateException when the state is not a case of `$enum`
     */
    public function toAs(string $enum): BackedEnum
    {
        return $enum::tryFrom($this->to) ?? throw UnknownStateException::notACase($this->to, $enum);
    }
}
