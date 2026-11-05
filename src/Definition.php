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
 *
 * Guard and hook objects are shared by every machine built from the definition, so keep them
 * stateless: in long-running workers (RoadRunner, Swoole, FrankenPHP) state kept in them leaks
 * between requests.
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

    /** @var array<string, true> declared states, for constant-time lookup */
    private array $stateIndex;

    /** @var array<string, array<string, list<Transition>>> transitions by source state and event, in declaration order */
    private array $bySource;

    /** @var array<string, list<Transition>> transitions grouped by event, in declaration order */
    private array $byEvent;

    /** @var array<string, list<Hook>> run after the machine has entered the state */
    private array $onEnter;

    /** @var array<string, list<Hook>> run before the machine leaves the state */
    private array $onExit;

    /**
     * @param string|BackedEnum $initial
     * @param list<string|BackedEnum> $states
     * @param array<mixed> $transitions every element must be a {@see Transition}
     * @param array<string, list<Hook|callable(TransitionContext): mixed>> $onEnter hooks by state name
     * @param array<string, list<Hook|callable(TransitionContext): mixed>> $onExit hooks by state name
     */
    public function __construct(
        string|BackedEnum $initial,
        array $states,
        array $transitions,
        array $onEnter = [],
        array $onExit = [],
    ) {
        $initial = Name::of($initial);
        $known = Name::listOf($states);

        if ($known === []) {
            throw new InvalidDefinitionException('A definition needs at least one state.');
        }

        if (in_array('', $known, true)) {
            throw new InvalidDefinitionException('State names must not be empty.');
        }

        $index = array_fill_keys($known, true);

        if (!isset($index[$initial])) {
            throw new InvalidDefinitionException(sprintf('Initial state "%s" is not a declared state.', $initial));
        }

        $checked = [];

        foreach ($transitions as $transition) {
            if (!$transition instanceof Transition) {
                throw new InvalidDefinitionException(sprintf(
                    'Transitions must be instances of %s.',
                    Transition::class,
                ));
            }

            foreach ([...$transition->from, $transition->to] as $state) {
                if (!isset($index[$state])) {
                    throw new InvalidDefinitionException(sprintf(
                        'Transition "%s" refers to undeclared state "%s".',
                        $transition->event,
                        $state,
                    ));
                }
            }

            $checked[] = $transition;
        }

        $byStateName = ['onEnter hook' => $onEnter, 'onExit hook' => $onExit];

        foreach ($byStateName as $kind => $byState) {
            foreach (array_keys($byState) as $state) {
                if (!isset($index[$state])) {
                    throw new InvalidDefinitionException(sprintf('%s refers to undeclared state "%s".', $kind, $state));
                }
            }
        }

        $this->onEnter = self::normalizeHooks($onEnter);
        $this->onExit = self::normalizeHooks($onExit);
        $this->initial = $initial;
        $this->states = $known;
        $this->transitions = $checked;

        $byEvent = [];
        $names = [];
        foreach ($checked as $transition) {
            $byEvent[$transition->event][] = $transition;
            $names[$transition->event] = true;
        }
        $this->byEvent = $byEvent;
        $this->eventNames = array_map(strval(...), array_keys($names));
        $this->stateIndex = $index;

        // Grouped by source, then by event in definition order (so availableEvents() keeps that order).
        $bySource = [];
        foreach ($byEvent as $event => $candidates) {
            foreach ($candidates as $transition) {
                foreach ($transition->from as $source) {
                    $bySource[$source][$event][] = $transition;
                }
            }
        }
        $this->bySource = $bySource;
    }

    /**
     * @param array<string, mixed> $byState
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
                if (!$hook instanceof Hook && !is_callable($hook)) {
                    throw new InvalidDefinitionException(sprintf(
                        'A hook for state "%s" must be a Hook or a callable.',
                        $state,
                    ));
                }

                $normalized[(string) $state][] = ClosureHook::wrap($hook);
            }
        }

        return $normalized;
    }

    /**
     * Guard-less definition from a plain table of `[event, from, to]` rows; states are inferred.
     * `from` may be a list of states. Use the builder when you need guards or hooks.
     *
     *     Definition::fromTable(S::Draft, [
     *         [E::Submit, S::Draft, S::Review],
     *         [E::Approve, S::Review, S::Published],
     *     ]);
     *
     * @param list<array{string|BackedEnum, string|BackedEnum|non-empty-list<string|BackedEnum>, string|BackedEnum}> $rows
     */
    public static function fromTable(string|BackedEnum $initial, array $rows): self
    {
        $states = [$initial];
        $transitions = [];

        foreach ($rows as [$event, $from, $to]) {
            $transition = Transition::on($event, $from, $to);
            $transitions[] = $transition;
            array_push($states, ...$transition->from, ...[$transition->to]);
        }

        return new self($initial, $states, $transitions);
    }

    public static function builder(): DefinitionBuilder
    {
        return new DefinitionBuilder();
    }

    public function toMermaid(): string
    {
        return Diagram::mermaid($this);
    }

    public function toDot(): string
    {
        return Diagram::dot($this);
    }

    /**
     * Declared states no sequence of events can reach from the initial state (guards are ignored:
     * this finds structural dead ends, e.g. a forgotten transition).
     *
     * @return list<string>
     */
    public function unreachableStates(): array
    {
        $reached = [$this->initial => true];
        $queue = [$this->initial];

        // Breadth-first; a moving index instead of array_shift() keeps each step O(1).
        for ($next = 0; isset($queue[$next]); $next++) {
            foreach ($this->bySource[$queue[$next]] ?? [] as $candidates) {
                foreach ($candidates as $transition) {
                    if (!isset($reached[$transition->to])) {
                        $reached[$transition->to] = true;
                        $queue[] = $transition->to;
                    }
                }
            }
        }

        return array_values(array_filter($this->states, static fn(string $s): bool => !isset($reached[$s])));
    }

    public function hasState(string $state): bool
    {
        return isset($this->stateIndex[$state]);
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
     * Transitions `$event` can take from `$state`, in declaration order (guards not evaluated).
     *
     * @return list<Transition>
     */
    public function transitionsFrom(string $state, string $event): array
    {
        return $this->bySource[$state][$event] ?? [];
    }

    /**
     * Events with at least one transition leaving `$state`, in definition order (guards not evaluated).
     *
     * @return list<string>
     */
    public function eventsFrom(string $state): array
    {
        return array_map(strval(...), array_keys($this->bySource[$state] ?? []));
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
