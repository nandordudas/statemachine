<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Contracts\StateMachine;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Contracts\TransactionalStateStore;
use Dudas\StateMachine\Exception\AfterHookFailedException;
use Dudas\StateMachine\Exception\ChainLimitExceededException;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use Dudas\StateMachine\Exception\UnknownEventException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Store\PropertyStore;
use Exception;
use Fiber;
use LogicException;

/**
 * Runtime instance of a {@see Definition}. The current state lives in a {@see StateStore},
 * so the machine can drive an in-memory value or state owned by a domain object.
 *
 * Sending an event runs: guards → `before` hooks → `onExit` hooks → store write → `onEnter` hooks → `after` hooks.
 * A failing guard, `before` or `onExit` hook leaves the state untouched.
 * A failing `onEnter`/`after` hook does not roll the state back and surfaces as {@see AfterHookFailedException}.
 *
 * Transitions run to completion: an event sent from an `onEnter`/`after` hook is queued and applied once every
 * hook of the current transition has run, still inside the outer send() call.
 */
final class Machine implements StateMachine
{
    private const string IDLE = 'idle';

    /** Evaluating the guards of the event being sent. */
    private const string SELECTING = 'selecting';

    /** `before`/`onExit` hooks and the store write. */
    private const string PREPARING = 'preparing';

    /** `onEnter`/`after` hooks, and events chained from them. */
    private const string FINISHING = 'finishing';

    /** How a refusal is reported: thrown (send) or returned (tryTransition). */
    private const int THROW = 0;

    private const int RESULT = 1;

    public string $state {
        get => $this->store->get();
    }

    private readonly StateStore $store;

    private string $phase = self::IDLE;

    /** The caller (0 = main, else a fiber id) applying the current transition. */
    private int $owner = 0;

    /** @var array<int, int> guard evaluations by can()/availableEvents() in progress, by caller */
    private array $evaluating = [];

    /** @var list<array{string, array<string, mixed>, self::THROW|self::RESULT}> events sent from hooks */
    private array $queue = [];

    /** @var array<int, TransitionContext> a ring buffer when the history is bounded */
    private array $history = [];

    /** Index of the oldest entry once a bounded history is full. */
    private int $historyStart = 0;

    /**
     * @param bool|int $recordHistory true keeps every applied transition, a positive int keeps only the latest N
     * @param int $maxChainedEvents how many events hooks may chain from one send() before it is treated as a cycle
     * @throws UnknownStateException when the store holds a state the definition doesn't know
     * @throws InvalidDefinitionException when a limit is negative or a PropertyStore cannot hold every state
     */
    public function __construct(
        public readonly Definition $definition,
        ?StateStore $store = null,
        private readonly bool|int $recordHistory = false,
        private readonly int $maxChainedEvents = 100,
    ) {
        if (is_int($recordHistory) && $recordHistory < 0 || $maxChainedEvents < 0) {
            throw new InvalidDefinitionException('History size and chained event limit must not be negative.');
        }

        $this->store = $store ?? new InMemoryStore($definition->initial);

        if ($this->store instanceof PropertyStore) {
            $this->store->assertCanHold($definition->states);
        }

        if (!$definition->hasState($this->state)) {
            throw UnknownStateException::named($this->state);
        }
    }

    /**
     * The current state as a case of `$enum`, for code that works with enums rather than state names:
     *
     *     $video->stateAs(VideoState::class);   // VideoState::Playing
     *
     * @template T of BackedEnum
     * @param class-string<T> $enum
     * @return T
     * @throws UnknownStateException when the state is not a case of `$enum`
     */
    public function stateAs(string $enum): BackedEnum
    {
        $state = $this->state;

        return $enum::tryFrom($state) ?? throw UnknownStateException::notACase($state, $enum);
    }

    public function is(string|BackedEnum ...$states): bool
    {
        $current = $this->state;

        foreach ($states as $state) {
            if (Name::of($state) === $current) {
                return true;
            }
        }

        return false;
    }

    public function can(string|BackedEnum $event, array $payload = []): bool
    {
        return $this->select(Name::of($event), $this->state, $payload) !== null;
    }

    public function availableEvents(array $payload = []): array
    {
        $from = $this->state;
        $available = [];

        foreach ($this->definition->eventsFrom($from) as $event) {
            if ($this->select($event, $from, $payload) !== null) {
                $available[] = $event;
            }
        }

        return $available;
    }

    /**
     * Why `$event` cannot be sent right now; empty when it can. Unlike {@see can()} it asks every guard, so each
     * reason is listed. Messages come from {@see because()} or an {@see Contracts\ExplainingGuard}; a guard that
     * gives none is reported as {@see Blocker::GUARD_REJECTED}.
     *
     *     foreach ($order->blockers(E::Ship, $payload) as $blocker) {
     *         $flash->warning($blocker->message);
     *     }
     *
     * @param array<string, mixed> $payload
     * @return list<Blocker>
     */
    public function blockers(string|BackedEnum $event, array $payload = []): array
    {
        $event = Name::of($event);
        $from = $this->state;

        if (!$this->definition->hasEvent($event)) {
            return [new Blocker(Blocker::UNKNOWN_EVENT, sprintf('Event "%s" is not defined.', $event), [
                'event' => $event,
            ])];
        }

        if ($this->definition->transitionsFrom($from, $event) === []) {
            return [new Blocker(
                Blocker::NOT_ALLOWED,
                sprintf('Event "%s" cannot be handled in state "%s".', $event, $from),
                ['event' => $event, 'from' => $from],
            )];
        }

        $caller = self::caller();
        $this->evaluating[$caller] = ($this->evaluating[$caller] ?? 0) + 1;

        try {
            return $this->guardBlockers($event, $from, $payload);
        } finally {
            if (--$this->evaluating[$caller] === 0) {
                unset($this->evaluating[$caller]);
            }
        }
    }

    public function send(string|BackedEnum $event, array $payload = []): static
    {
        $this->dispatch(Name::of($event), $payload, self::THROW);

        return $this;
    }

    /**
     * Not atomic: events applied before a failing one stay applied.
     */
    public function sendAll(iterable $events): static
    {
        foreach ($events as $item) {
            [$event, $payload] = is_array($item) ? [$item[0], $item[1] ?? []] : [$item, []];

            $this->dispatch(Name::of($event), $payload, self::THROW);
        }

        return $this;
    }

    /**
     * Transitions applied so far, oldest first. Only recorded when the machine was created with
     * `recordHistory: true` (unbounded) or `recordHistory: N` (the latest N).
     *
     * @return list<TransitionContext>
     */
    public function history(): array
    {
        if ($this->historyStart === 0) {
            return array_values($this->history);
        }

        return [
            ...array_slice($this->history, $this->historyStart),
            ...array_slice($this->history, 0, $this->historyStart),
        ];
    }

    public function tryTransition(string|BackedEnum $event, array $payload = []): TransitionResult
    {
        $result = $this->dispatch(Name::of($event), $payload, self::RESULT);

        return $result instanceof TransitionResult ? $result : throw new LogicException('A result was requested.');
    }

    /**
     * Entry point of every send: rejects re-entry that could only race, queues events sent from
     * `onEnter`/`after` hooks, and drains that queue in a loop (no recursion, bounded length).
     *
     * @param array<string, mixed> $payload
     * @param self::THROW|self::RESULT $mode how to report a refusal
     * @return TransitionResult|bool a TransitionResult in result mode; otherwise true (the event was applied
     *                               or queued)
     */
    private function dispatch(string $event, array $payload, int $mode): TransitionResult|bool
    {
        $caller = self::caller();

        // Only the rare re-entrant case pays for these checks.
        if ($this->phase !== self::IDLE || $this->evaluating !== []) {
            if (isset($this->evaluating[$caller]) || $this->phase === self::SELECTING && $caller === $this->owner) {
                throw ConcurrentTransitionException::sendFromGuard($event);
            }

            if ($this->phase !== self::IDLE) {
                if ($caller !== $this->owner) {
                    throw ConcurrentTransitionException::busy($event);
                }

                if ($this->phase === self::PREPARING) {
                    throw ConcurrentTransitionException::nestedSend($event);
                }

                $this->queue[] = [$event, $payload, $mode];

                return $mode === self::RESULT ? TransitionResult::queued($this->state) : true;
            }
        }

        $this->owner = $caller;

        try {
            $outcome = $this->step($event, $payload);

            if ($this->queue !== []) {
                $this->drain();
            }

            if ($outcome instanceof Refusal) {
                return $this->refuse($outcome, $mode);
            }

            return $mode === self::RESULT ? TransitionResult::succeeded($outcome->to, $outcome) : true;
        } finally {
            $this->phase = self::IDLE;
            $this->owner = 0;
            $this->queue = [];
        }
    }

    /**
     * Applies the events hooks queued, in order. A moving index instead of array_shift() keeps it O(n).
     */
    private function drain(): void
    {
        for ($next = 0; isset($this->queue[$next]); $next++) {
            [$event, $payload, $mode] = $this->queue[$next];

            if ($next >= $this->maxChainedEvents) {
                throw ChainLimitExceededException::after($this->maxChainedEvents, $event);
            }

            $outcome = $this->step($event, $payload);

            if ($outcome instanceof Refusal) {
                $this->refuse($outcome, $mode); // throws for a queued send(); a queued try's refusal is dropped
            }
        }
    }

    /**
     * Applies one event. Selection, `before`/`onExit` hooks and the write run inside the store's transaction
     * when it has one; `onEnter`/`after` hooks run after it.
     *
     * @param array<string, mixed> $payload
     */
    private function step(string $event, array $payload): TransitionContext|Refusal
    {
        $prepared = $this->store instanceof TransactionalStateStore
            ? $this->store->transaction(fn(): Refusal|array => $this->prepare($event, $payload))
            : $this->prepare($event, $payload);

        if ($prepared instanceof Refusal) {
            return $prepared;
        }

        [$transition, $context] = $prepared;
        $this->phase = self::FINISHING;

        if ($this->recordHistory !== false) {
            $this->record($context);
        }

        $failures = [];

        foreach ($this->definition->enterHooksFor($context->to) as $hook) {
            try {
                $hook->handle($context);
            } catch (Exception $e) { // Errors are bugs, not hook failures: let them propagate
                $failures[] = $e;
            }
        }

        foreach ($transition->after as $hook) {
            try {
                $hook->handle($context);
            } catch (Exception $e) {
                $failures[] = $e;
            }
        }

        if ($failures !== []) {
            $this->queue = [];

            throw new AfterHookFailedException($context, $failures[0], $failures);
        }

        return $context;
    }

    /**
     * Selects the transition, runs `before`/`onExit` hooks and writes the new state.
     *
     * @param array<string, mixed> $payload
     * @return Refusal|array{Transition, TransitionContext} why the event was refused, or the applied transition
     */
    private function prepare(string $event, array $payload): Refusal|array
    {
        $this->phase = self::SELECTING;
        $from = $this->store->get();

        if (!$this->definition->hasState($from)) {
            throw UnknownStateException::named($from);
        }

        if (!$this->definition->hasEvent($event)) {
            return new Refusal($event, $from, Refusal::UNKNOWN_EVENT);
        }

        $transition = $this->firstPermitted($event, $from, $payload);

        if ($transition === null) {
            return new Refusal(
                $event,
                $from,
                $this->definition->transitionsFrom($from, $event) === []
                    ? Refusal::NOT_ALLOWED
                    : Refusal::GUARD_REJECTED,
                $payload,
                // Asked now, in the same pass and under the same lock that refused the event: asking later could
                // see other data (or run a guard that throws) after the decision was taken.
                $this->guardBlockers($event, $from, $payload),
            );
        }

        $this->phase = self::PREPARING;
        $context = $transition->contextFor($from, $payload);

        foreach ($transition->before as $hook) {
            $hook->handle($context);
        }

        foreach ($this->definition->exitHooksFor($from) as $hook) {
            $hook->handle($context);
        }

        // A hook, or another writer while a guard or hook was suspended, may have changed the state:
        // don't apply a stale transition.
        $now = $this->store->get();

        if ($now !== $from) {
            throw ConcurrentTransitionException::forEvent($event, $from, $now);
        }

        // A self-loop changes nothing, so there is nothing to write (and an `UPDATE ... SET s = :s WHERE s = :s`
        // reports zero affected rows on MySQL, which a compare-and-set would mistake for a lost race).
        if ($from === $transition->to) {
            return [$transition, $context];
        }

        if ($this->store instanceof AtomicStateStore) {
            if (!$this->store->compareAndSet($from, $transition->to)) {
                throw ConcurrentTransitionException::forEvent($event, $from, $this->store->get());
            }
        } else {
            $this->store->set($transition->to);
        }

        return [$transition, $context];
    }

    /**
     * @param self::THROW|self::RESULT $mode
     */
    private function refuse(Refusal $refusal, int $mode): TransitionResult
    {
        $exception = match ($refusal->reason) {
            Refusal::UNKNOWN_EVENT => UnknownEventException::named($refusal->event),
            Refusal::GUARD_REJECTED => GuardRejectedException::forEvent(
                $refusal->event,
                $refusal->from,
                $refusal->blockers,
            ),
            Refusal::NOT_ALLOWED => TransitionNotAllowedException::fromState($refusal->event, $refusal->from),
        };

        return $mode === self::RESULT ? TransitionResult::refused($refusal->from, $exception) : throw $exception;
    }

    private function record(TransitionContext $context): void
    {
        if ($this->recordHistory === 0) {
            return;
        }

        if (!is_int($this->recordHistory) || count($this->history) < $this->recordHistory) {
            $this->history[] = $context;

            return;
        }

        // Full: overwrite the oldest entry instead of shifting every element.
        $this->history[$this->historyStart] = $context;
        $this->historyStart = ($this->historyStart + 1) % $this->recordHistory;
    }

    /**
     * The first transition whose guards allow it. Used outside send(), so a guard that sends is detected.
     *
     * @param array<string, mixed> $payload
     */
    private function select(string $event, string $from, array $payload): ?Transition
    {
        $caller = self::caller();
        $this->evaluating[$caller] = ($this->evaluating[$caller] ?? 0) + 1;

        try {
            return $this->firstPermitted($event, $from, $payload);
        } finally {
            if (--$this->evaluating[$caller] === 0) {
                unset($this->evaluating[$caller]);
            }
        }
    }

    /**
     * Reasons of every candidate transition's guards, without duplicates; empty when one of them is permitted.
     *
     * @param array<string, mixed> $payload
     * @return list<Blocker>
     */
    private function guardBlockers(string $event, string $from, array $payload): array
    {
        $blockers = [];

        foreach ($this->definition->transitionsFrom($from, $event) as $transition) {
            $reasons = $transition->blockers($from, $payload);

            if ($reasons === []) {
                return [];
            }

            foreach ($reasons as $blocker) {
                $blockers[$blocker->code . "\0" . $blocker->message] = $blocker;
            }
        }

        return array_values($blockers);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function firstPermitted(string $event, string $from, array $payload): ?Transition
    {
        foreach ($this->definition->transitionsFrom($from, $event) as $transition) {
            if ($transition->permits($from, $payload)) {
                return $transition;
            }
        }

        return null;
    }

    /** 0 for the main program, otherwise the id of the running fiber. */
    private static function caller(): int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? 0 : spl_object_id($fiber);
    }
}
