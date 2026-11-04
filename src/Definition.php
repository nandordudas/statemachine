<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * The immutable blueprint of a machine: states, initial state and transitions.
 * It carries no runtime state, so one definition can drive any number of machines.
 *
 * Several transitions may share an event and source state; the first whose guards
 * allow it wins, which expresses conditional branching.
 */
final class Definition
{
    /** @var string @readonly */
    public $initial;

    /** @var string[] @readonly non-empty */
    public $states;

    /** @var Transition[] @readonly */
    public $transitions;

    /** @var string[] */
    private $eventNames;

    /** @var array<string, Transition[]> transitions grouped by event, in declaration order */
    private $byEvent;

    /** @var array<string, Hook[]> */
    private $onEnter;

    /** @var array<string, Hook[]> */
    private $onExit;

    /**
     * @param string[] $states
     * @param Transition[] $transitions
     * @param array<string, Hook[]|Hook|callable> $onEnter hooks by state name
     * @param array<string, Hook[]|Hook|callable> $onExit hooks by state name
     */
    public function __construct(
        string $initial,
        array $states,
        array $transitions,
        array $onEnter = [],
        array $onExit = []
    ) {
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
                throw new InvalidDefinitionException(sprintf(
                    'Transitions must be instances of %s.',
                    Transition::class
                ));
            }

            foreach (array_merge($transition->from, [$transition->to]) as $state) {
                if (!in_array($state, $known, true)) {
                    throw new InvalidDefinitionException(sprintf(
                        'Transition "%s" refers to undeclared state "%s".',
                        $transition->event,
                        $state
                    ));
                }
            }
        }

        foreach (['onEnter' => $onEnter, 'onExit' => $onExit] as $kind => $byState) {
            foreach (array_keys($byState) as $state) {
                if (!in_array((string) $state, $known, true)) {
                    throw new InvalidDefinitionException(sprintf(
                        '%s hook refers to undeclared state "%s".',
                        $kind,
                        $state
                    ));
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
        $this->eventNames = array_map('strval', array_keys($names));
    }

    /**
     * @param array<string, Hook[]|Hook|callable> $byState
     * @return array<string, Hook[]>
     */
    private static function normalizeHooks(array $byState): array
    {
        $normalized = [];

        foreach ($byState as $state => $hooks) {
            foreach (is_array($hooks) ? $hooks : [$hooks] as $hook) {
                $normalized[(string) $state][] = ClosureHook::wrap($hook);
            }
        }

        return $normalized;
    }

    public static function builder(): DefinitionBuilder
    {
        return new DefinitionBuilder();
    }

    /**
     * Guard-less definition from a plain table of `[event, from, to]` rows; states are inferred.
     * `from` may be a list of states. Use the builder when you need guards or hooks.
     *
     * @param array<int, array{0: string, 1: string|string[], 2: string}> $rows
     */
    public static function fromTable(string $initial, array $rows): self
    {
        $states = [$initial];
        $transitions = [];

        foreach ($rows as $row) {
            $transition = Transition::on($row[0], $row[1], $row[2]);
            $transitions[] = $transition;
            $states = array_merge($states, $transition->from, [$transition->to]);
        }

        return new self($initial, $states, $transitions);
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
     * @return string[]
     */
    public function unreachableStates(): array
    {
        $reached = [$this->initial => true];
        $queue = [$this->initial];

        while ($queue !== []) {
            $state = array_shift($queue);

            foreach ($this->transitions as $transition) {
                if ($transition->leavesFrom($state) && !isset($reached[$transition->to])) {
                    $reached[$transition->to] = true;
                    $queue[] = $transition->to;
                }
            }
        }

        return array_values(array_filter($this->states, static function (string $s) use ($reached): bool {
            return !isset($reached[$s]);
        }));
    }

    public function hasState(string $state): bool
    {
        return in_array($state, $this->states, true);
    }

    /**
     * @return string[]
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
     * @return Transition[] in declaration order
     */
    public function transitionsFor(string $event): array
    {
        return $this->byEvent[$event] ?? [];
    }

    /**
     * @return Hook[]
     */
    public function enterHooksFor(string $state): array
    {
        return $this->onEnter[$state] ?? [];
    }

    /**
     * @return Hook[]
     */
    public function exitHooksFor(string $state): array
    {
        return $this->onExit[$state] ?? [];
    }
}
