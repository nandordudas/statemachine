<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Contracts\Hook;
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

    /** @var list<string> */
    private array $eventNames;

    /** @var array<string, list<Transition>> transitions grouped by event, in declaration order */
    private array $byEvent;

    /** @var array<string, list<Hook>> run after the machine has entered the state */
    private array $onEnter;

    /** @var array<string, list<Hook>> run before the machine leaves the state */
    private array $onExit;

    /**
     * @param string|BackedEnum $initial
     * @param list<string|BackedEnum> $states
     * @param list<Transition> $transitions
     * @param array<string, list<Hook>> $onEnter hooks by state name
     * @param array<string, list<Hook>> $onExit hooks by state name
     */
    public function __construct(string|BackedEnum $initial, array $states, array $transitions, array $onEnter = [], array $onExit = [])
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

        foreach (['onEnter' => $onEnter, 'onExit' => $onExit] as $kind => $byState) {
            foreach (array_keys($byState) as $state) {
                if (!in_array((string) $state, $known, true)) {
                    throw new InvalidDefinitionException(sprintf('%s hook refers to undeclared state "%s".', $kind, $state));
                }
            }
        }

        $this->onEnter = self::normalizeHooks($onEnter);
        $this->onExit = self::normalizeHooks($onExit);
        $this->initial = $initial;
        $this->states = $known;
        $this->transitions = $transitions;

        $byEvent = [];
        $names = [];
        foreach ($transitions as $transition) {
            $byEvent[$transition->event][] = $transition;
            $names[$transition->event] = true;
        }
        $this->byEvent = $byEvent;
        $this->eventNames = array_map(strval(...), array_keys($names));
    }

    /**
     * @param array<string, iterable<Hook|callable>> $byState
     * @return array<string, list<Hook>>
     */
    private static function normalizeHooks(array $byState): array
    {
        $normalized = [];

        foreach ($byState as $state => $hooks) {
            if (!is_iterable($hooks)) {
                throw new InvalidDefinitionException(sprintf('State hooks for "%s" must be a list of hooks.', $state));
            }

            foreach ($hooks as $hook) {
                $normalized[(string) $state][] = ClosureHook::wrap($hook);
            }
        }

        return $normalized;
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
        return $this->eventNames;
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

    /**
     * @return list<Hook>
     */
    public function enterHooksFor(string $state): array
    {
        return $this->onEnter[$state] ?? [];
    }

    /**
     * @return list<Hook>
     */
    public function exitHooksFor(string $state): array
    {
        return $this->onExit[$state] ?? [];
    }
}
