<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * The immutable blueprint of a machine: states, initial state and transitions.
 * It carries no runtime state, so one definition can drive any number of machines.
 *
 * Several transitions may share an event and source state; the first whose guards
 * allow it wins, which expresses conditional branching.
 */
final readonly class Definition
{
    public string $initial;

    /** @var non-empty-list<string> */
    public array $states;

    /** @var list<Transition> */
    public array $transitions;

    /** @var array<string, list<Transition>> transitions grouped by event, in declaration order */
    private array $byEvent;

    /**
     * @param string|BackedEnum $initial
     * @param list<string|BackedEnum> $states
     * @param list<Transition> $transitions
     */
    public function __construct(string|BackedEnum $initial, array $states, array $transitions)
    {
        $initial = Name::of($initial);
        $known = Name::listOf($states);

        if ($known === []) {
            throw new InvalidDefinitionException('A definition needs at least one state.');
        }

        if (in_array('', $known, true)) {
            throw new InvalidDefinitionException('State names must not be empty.');
        }

        if (!in_array($initial, $known, true)) {
            throw new InvalidDefinitionException(sprintf('Initial state "%s" is not a declared state.', $initial));
        }

        $transitions = array_values($transitions);

        foreach ($transitions as $transition) {
            if (!$transition instanceof Transition) {
                throw new InvalidDefinitionException(sprintf('Transitions must be instances of %s.', Transition::class));
            }

            foreach ([...$transition->from, $transition->to] as $state) {
                if (!in_array($state, $known, true)) {
                    throw new InvalidDefinitionException(sprintf(
                        'Transition "%s" refers to undeclared state "%s".',
                        $transition->event,
                        $state,
                    ));
                }
            }
        }

        $this->initial = $initial;
        $this->states = $known;
        $this->transitions = $transitions;

        $byEvent = [];
        foreach ($transitions as $transition) {
            $byEvent[$transition->event][] = $transition;
        }
        $this->byEvent = $byEvent;
    }

    public static function builder(): DefinitionBuilder
    {
        return new DefinitionBuilder();
    }

    public function hasState(string $state): bool
    {
        return in_array($state, $this->states, true);
    }

    /**
     * @return list<string>
     */
    public function events(): array
    {
        return array_map(strval(...), array_keys($this->byEvent));
    }

    public function hasEvent(string $event): bool
    {
        return isset($this->byEvent[$event]);
    }

    /**
     * @return list<Transition> in declaration order
     */
    public function transitionsFor(string $event): array
    {
        return $this->byEvent[$event] ?? [];
    }
}
