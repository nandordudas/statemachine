<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * Fluent assembly of a {@see Definition}:
 *
 *     $definition = Definition::builder()
 *         ->initial('stopped')
 *         ->states('playing', 'stopped', 'paused')
 *         ->transition(Transition::on('PLAY', ['stopped', 'paused'], 'playing'))
 *         ->build();
 */
final class DefinitionBuilder
{
    private string|BackedEnum|null $initial = null;

    /** @var list<string|BackedEnum> */
    private array $states = [];

    /** @var list<Transition> */
    private array $transitions = [];

    public function initial(string|BackedEnum $state): self
    {
        $this->initial = $state;

        return $this;
    }

    public function states(string|BackedEnum ...$states): self
    {
        array_push($this->states, ...$states);

        return $this;
    }

    public function transition(Transition ...$transitions): self
    {
        array_push($this->transitions, ...$transitions);

        return $this;
    }

    public function build(): Definition
    {
        return new Definition(
            $this->initial ?? throw new InvalidDefinitionException('An initial state is required.'),
            $this->states,
            $this->transitions,
        );
    }
}
