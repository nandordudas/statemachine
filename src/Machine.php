<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Contracts\StateMachine;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Exception\AfterHookFailedException;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use Dudas\StateMachine\Exception\UnknownEventException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Store\InMemoryStore;
use Exception;

/**
 * Runtime instance of a {@see Definition}. The current state lives in a {@see StateStore},
 * so the machine can drive an in-memory value or state owned by a domain object.
 *
 * Sending an event runs: guards → `before` hooks → `onExit` hooks → store write → `onEnter` hooks → `after` hooks.
 * A failing guard, `before` or `onExit` hook leaves the state untouched. A failing `onEnter`/`after` hook
 * does not roll the state back and surfaces as {@see AfterHookFailedException}.
 */
final class Machine implements StateMachine
{
    public string $state {
        get => $this->store->get();
    }

    private readonly StateStore $store;

    /** True while `before`/`onExit` hooks run; a nested send() then could only race with this one. */
    private bool $inPreHooks = false;

    /** @var list<TransitionContext> */
    private array $history = [];

    /**
     * @throws UnknownStateException when the store holds a state the definition doesn't know
     */
    public function __construct(
        public readonly Definition $definition,
        ?StateStore $store = null,
        private readonly bool $recordHistory = false,
    ) {
        $this->store = $store ?? new InMemoryStore($definition->initial);

        if (!$definition->hasState($this->state)) {
            throw UnknownStateException::named($this->state);
        }
    }

    public function is(string|BackedEnum ...$states): bool
    {
        return in_array($this->state, Name::listOf($states), true);
    }

    public function can(string|BackedEnum $event, array $payload = []): bool
    {
        return $this->select(Name::of($event), $this->state, $payload) !== null;
    }

    public function availableEvents(array $payload = []): array
    {
        $from = $this->state;

        return array_values(array_filter(
            $this->definition->events(),
            fn(string $event): bool => $this->select($event, $from, $payload) !== null,
        ));
    }

    public function send(string|BackedEnum $event, array $payload = []): static
    {
        $event = Name::of($event);
        $from = $this->guardState($event);
        $transition = $this->plan($event, $from, $payload);

        if ($transition instanceof Exception) {
            throw $transition;
        }

        $this->apply($transition, $event, $from, $payload);

        return $this;
    }

    public function sendAll(iterable $events): static
    {
        foreach ($events as $item) {
            [$event, $payload] = is_array($item) ? [$item[0], $item[1] ?? []] : [$item, []];

            $this->send($event, $payload);
        }

        return $this;
    }

    /**
     * Transitions applied so far, oldest first. Only recorded when the machine was created with
     * `recordHistory: true` (it grows without bound, so it is opt-in).
     *
     * @return list<TransitionContext>
     */
    public function history(): array
    {
        return $this->history;
    }

    public function sendIfAllowed(string|BackedEnum $event, array $payload = []): bool
    {
        return $this->tryTransition($event, $payload)->ok();
    }

    public function tryTransition(string|BackedEnum $event, array $payload = []): TransitionResult
    {
        $event = Name::of($event);
        $from = $this->guardState($event);
        $transition = $this->plan($event, $from, $payload);

        if ($transition instanceof Exception) {
            return TransitionResult::refused($from, $transition);
        }

        $this->apply($transition, $event, $from, $payload);

        return TransitionResult::succeeded($this->state);
    }

    /**
     * Preconditions that are programming errors rather than refusals, so they always throw.
     */
    private function guardState(string $event): string
    {
        if ($this->inPreHooks) {
            throw ConcurrentTransitionException::nestedSend($event);
        }

        $from = $this->state;

        if (!$this->definition->hasState($from)) {
            throw UnknownStateException::named($from);
        }

        return $from;
    }

    /**
     * @param array<string, mixed> $payload
     * @return Transition|(UnknownEventException|TransitionNotAllowedException) the refusal, not thrown
     */
    private function plan(string $event, string $from, array $payload): Transition|UnknownEventException|TransitionNotAllowedException
    {
        if (!$this->definition->hasEvent($event)) {
            return UnknownEventException::named($event);
        }

        $transition = $this->select($event, $from, $payload);

        if ($transition !== null) {
            return $transition;
        }

        return array_any(
            $this->definition->transitionsFor($event),
            static fn(Transition $t): bool => $t->leavesFrom($from),
        )
            ? GuardRejectedException::forEvent($event, $from)
            : TransitionNotAllowedException::fromState($event, $from);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function apply(Transition $transition, string $event, string $from, array $payload): void
    {
        $context = $transition->contextFor($from, $payload);

        $this->inPreHooks = true;

        try {
            foreach ([...$transition->before, ...$this->definition->exitHooksFor($from)] as $hook) {
                $hook->handle($context);
            }
        } finally {
            $this->inPreHooks = false;
        }

        // Another writer may have changed the state meanwhile; don't overwrite it with a stale transition.
        if ($this->state !== $from) {
            throw ConcurrentTransitionException::forEvent($event, $from, $this->state);
        }

        $this->write($event, $from, $transition->to);

        if ($this->recordHistory) {
            $this->history[] = $context;
        }

        try {
            foreach ([...$this->definition->enterHooksFor($transition->to), ...$transition->after] as $hook) {
                $hook->handle($context);
            }
        } catch (Exception $e) { // Errors are bugs, not hook failures: let them propagate
            throw new AfterHookFailedException($context, $e);
        }
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

    /**
     * @param array<string, mixed> $payload
     */
    private function select(string $event, string $from, array $payload): ?Transition
    {
        return array_find(
            $this->definition->transitionsFor($event),
            static fn(Transition $t): bool => $t->permits($from, $payload),
        );
    }
}
