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
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Store\PropertyStore;
use Exception;
use Fiber;
use Generator;
use LogicException;
use Throwable;

/**
 * Runtime instance of a {@see Definition}. The current state lives in a {@see StateStore},
 * so the machine can drive an in-memory value or state owned by a domain object.
 *
 * Sending an event runs: guards → `before` hooks → `onExit` hooks → store write → `onEnter` hooks → `after` hooks.
 * A failing guard, `before` or `onExit` hook leaves the state untouched (and runs the transition's `abort` hooks).
 * A failing `onEnter`/`after` hook does not roll the state back and surfaces as {@see AfterHookFailedException}.
 *
 * Transitions run to completion: an event sent from an `onEnter`/`after` hook is queued and applied once every
 * hook of the current transition has run, still inside the outer send() call.
 */
final class Machine implements StateMachine
{
    private const string IDLE = 'idle';

    /** Selecting the transition, `before`/`onExit` hooks, the store write. */
    private const string PREPARING = 'preparing';

    /** `onEnter`/`after` hooks, and events chained from them. */
    private const string FINISHING = 'finishing';

    /** How a refusal is reported: thrown (send), returned (tryTransition) or only as false (sendIfAllowed). */
    private const int THROW = 0;

    private const int RESULT = 1;

    private const int QUIET = 2;

    public string $state {
        get => $this->store->get();
    }

    private readonly StateStore $store;

    private string $phase = self::IDLE;

    /** The caller (0 = main, else a fiber id) applying the current transition. */
    private int $owner = 0;

    /** @var array<int, int> guard evaluations in progress, by caller */
    private array $evaluating = [];

    /** @var list<array{string, array<string, mixed>, self::THROW|self::RESULT|self::QUIET}> events sent from hooks */
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

    public function is(string|BackedEnum ...$states): bool
    {
        $current = $this->state;

        return array_any($states, static fn(string|BackedEnum $state): bool => Name::of($state) === $current);
    }

    public function can(string|BackedEnum $event, array $payload = []): bool
    {
        return $this->select(Name::of($event), $this->state, $payload) !== null;
    }

    public function availableEvents(array $payload = []): array
    {
        $from = $this->state;

        return array_values(array_filter(
            $this->definition->eventsFrom($from),
            fn(string $event): bool => $this->select($event, $from, $payload) !== null,
        ));
    }

    public function send(string|BackedEnum $event, array $payload = []): static
    {
        $this->dispatch(Name::of($event), $payload, self::THROW);

        return $this;
    }

    /**
     * Not atomic: events applied before a failing one stay applied. Use {@see stream()} to see each outcome.
     */
    public function sendAll(iterable $events): static
    {
        foreach ($events as $item) {
            [$event, $payload] = self::item($item);

            $this->send($event, $payload);
        }

        return $this;
    }

    /**
     * Lazily tries each event in order and yields its {@see TransitionResult}; refusals do not stop the stream.
     * Works with endless sources (a queue consumer): stop iterating to stop sending.
     *
     * @param iterable<string|BackedEnum|array{0: string|BackedEnum, 1?: array<string, mixed>}> $events
     * @return Generator<int, TransitionResult, mixed, void>
     */
    public function stream(iterable $events): Generator
    {
        foreach ($events as $item) {
            [$event, $payload] = self::item($item);

            yield $this->tryTransition($event, $payload);
        }
    }

    /**
     * Runs a process written as a generator: it yields events (or `[event, payload]`) and receives the
     * {@see TransitionResult} of each one, so multi-step flows can branch on outcomes without nesting hooks.
     *
     *     $machine->drive((function () {
     *         $result = yield E::Submit;
     *         if ($result->ok()) {
     *             yield [E::Approve, ['role' => 'approver']];
     *         }
     *     })());
     *
     * @template R
     * @param Generator<mixed, string|BackedEnum|array{0: string|BackedEnum, 1?: array<string, mixed>}, TransitionResult, R> $process
     * @return R the generator's return value
     */
    public function drive(Generator $process): mixed
    {
        while ($process->valid()) {
            [$event, $payload] = self::item($process->current());

            $process->send($this->tryTransition($event, $payload));
        }

        return $process->getReturn();
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

    public function sendIfAllowed(string|BackedEnum $event, array $payload = []): bool
    {
        return $this->dispatch(Name::of($event), $payload, self::QUIET)?->ok() ?? false;
    }

    public function tryTransition(string|BackedEnum $event, array $payload = []): TransitionResult
    {
        return (
            $this->dispatch(Name::of($event), $payload, self::RESULT) ?? throw new LogicException(
                'Only a quiet send reports a refusal as null.',
            )
        );
    }

    /**
     * Entry point of every send: rejects re-entry that could only race, queues events sent from
     * `onEnter`/`after` hooks, and drains that queue in a loop (no recursion, bounded length).
     *
     * @param array<string, mixed> $payload
     * @param self::THROW|self::RESULT|self::QUIET $mode how to report a refusal
     * @return TransitionResult|null null for a refusal in quiet mode
     */
    private function dispatch(string $event, array $payload, int $mode): ?TransitionResult
    {
        $caller = self::caller();

        if (isset($this->evaluating[$caller])) {
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

            return TransitionResult::queued($this->state);
        }

        $this->owner = $caller;

        try {
            $result = self::report($this->step($event, $payload), $mode);

            // A moving index instead of array_shift(): draining n chained events stays O(n).
            for ($next = 0; isset($this->queue[$next]); $next++) {
                [$chained, $chainedPayload, $chainedMode] = $this->queue[$next];

                if ($next >= $this->maxChainedEvents) {
                    throw ChainLimitExceededException::after($this->maxChainedEvents, $chained);
                }

                self::report($this->step($chained, $chainedPayload), $chainedMode);
            }

            return $result;
        } finally {
            $this->phase = self::IDLE;
            $this->owner = 0;
            $this->queue = [];
        }
    }

    /**
     * Applies one event. Selection, `before`/`onExit` hooks and the write run inside the store's transaction
     * when it has one; `onEnter`/`after` hooks run after it.
     *
     * @param array<string, mixed> $payload
     */
    private function step(string $event, array $payload): TransitionResult|Refusal
    {
        $this->phase = self::PREPARING;
        $attempt = new Attempt();

        try {
            $refusal = $this->store instanceof TransactionalStateStore
                ? $this->store->transaction(fn(): ?Refusal => $this->prepare($event, $payload, $attempt))
                : $this->prepare($event, $payload, $attempt);
        } catch (Throwable $e) {
            if ($attempt->transition !== null && $attempt->context !== null) {
                foreach ($attempt->transition->abort as $hook) {
                    $hook->handle($attempt->context);
                }
            }

            throw $e;
        }

        if ($refusal !== null) {
            return $refusal;
        }

        $transition = $attempt->transition;
        $context = $attempt->context;

        if ($transition === null || $context === null) {
            throw new LogicException('A transition that was not refused must have been applied.');
        }

        $this->phase = self::FINISHING;
        $this->record($context);

        $failures = [];

        foreach ([...$this->definition->enterHooksFor($context->to), ...$transition->after] as $hook) {
            try {
                $hook->handle($context);
            } catch (Exception $e) { // Errors are bugs, not hook failures: let them propagate
                $failures[] = $e;
            }
        }

        if ($failures !== []) {
            $this->queue = [];

            throw new AfterHookFailedException($context, $failures[0], $failures);
        }

        return TransitionResult::succeeded($context->to, $context);
    }

    /**
     * Selects the transition, runs `before`/`onExit` hooks and writes the new state, recording progress
     * in `$attempt`.
     *
     * @param array<string, mixed> $payload
     * @return Refusal|null why the event was refused, or null once the new state is written
     */
    private function prepare(string $event, array $payload, Attempt $attempt): ?Refusal
    {
        $from = $this->store->get();

        if (!$this->definition->hasState($from)) {
            throw UnknownStateException::named($from);
        }

        $transition = $this->plan($event, $from, $payload);

        if (!$transition instanceof Transition) {
            return $transition;
        }

        $context = $transition->contextFor($from, $payload);
        $attempt->transition = $transition;
        $attempt->context = $context;

        foreach ([...$transition->before, ...$this->definition->exitHooksFor($from)] as $hook) {
            $hook->handle($context);
        }

        // Another writer may have changed the state meanwhile; don't overwrite it with a stale transition.
        $now = $this->store->get();

        if ($now !== $from) {
            throw ConcurrentTransitionException::forEvent($event, $from, $now);
        }

        $this->write($event, $from, $transition->to);

        return null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function plan(string $event, string $from, array $payload): Transition|Refusal
    {
        if (!$this->definition->hasEvent($event)) {
            return new Refusal($event, $from, Refusal::UNKNOWN_EVENT);
        }

        $transition = $this->select($event, $from, $payload);

        if ($transition !== null) {
            return $transition;
        }

        return new Refusal(
            $event,
            $from,
            $this->definition->transitionsFrom($from, $event) === [] ? Refusal::NOT_ALLOWED : Refusal::GUARD_REJECTED,
        );
    }

    /**
     * @param self::THROW|self::RESULT|self::QUIET $mode
     */
    private static function report(TransitionResult|Refusal $outcome, int $mode): ?TransitionResult
    {
        if ($outcome instanceof TransitionResult) {
            return $outcome;
        }

        return match ($mode) {
            self::THROW => throw $outcome->exception(),
            self::RESULT => TransitionResult::refused($outcome->from, $outcome->exception()),
            self::QUIET => null,
        };
    }

    private function write(string $event, string $from, string $to): void
    {
        if ($this->store instanceof AtomicStateStore) {
            if (!$this->store->compareAndSet($from, $to)) {
                throw ConcurrentTransitionException::forEvent($event, $from, $this->store->get());
            }

            return;
        }

        $this->store->set($to);
    }

    private function record(TransitionContext $context): void
    {
        if ($this->recordHistory === false || $this->recordHistory === 0) {
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
     * @param array<string, mixed> $payload
     */
    private function select(string $event, string $from, array $payload): ?Transition
    {
        $caller = self::caller();
        $this->evaluating[$caller] = ($this->evaluating[$caller] ?? 0) + 1;

        try {
            return array_find(
                $this->definition->transitionsFrom($from, $event),
                static fn(Transition $t): bool => $t->permits($from, $payload),
            );
        } finally {
            if (--$this->evaluating[$caller] === 0) {
                unset($this->evaluating[$caller]);
            }
        }
    }

    /**
     * @param string|BackedEnum|array{0: string|BackedEnum, 1?: array<string, mixed>} $item
     * @return array{string|BackedEnum, array<string, mixed>}
     */
    private static function item(string|BackedEnum|array $item): array
    {
        return is_array($item) ? [$item[0], $item[1] ?? []] : [$item, []];
    }

    /** 0 for the main program, otherwise the id of the running fiber. */
    private static function caller(): int
    {
        $fiber = Fiber::getCurrent();

        return $fiber === null ? 0 : spl_object_id($fiber);
    }
}
