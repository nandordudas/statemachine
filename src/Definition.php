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

        if (!in_array($initial, $known, true)) {
            throw new InvalidDefinitionException(sprintf('Initial state "%s" is not a declared state.', $initial));
        }

        foreach ($transitions as $transition) {
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
        return array_values(array_unique(array_map(static fn(Transition $t): string => $t->event, $this->transitions)));
    }

    public function hasEvent(string $event): bool
    {
        return array_any($this->transitions, static fn(Transition $t): bool => $t->event === $event);
    }

    /**
     * @return list<Transition> in declaration order
     */
    public function transitionsFor(string $event): array
    {
        return array_values(array_filter($this->transitions, static fn(Transition $t): bool => $t->event === $event));
    }
}
