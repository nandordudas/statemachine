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
use Throwable;

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

    /**
     * @throws UnknownStateException when the store holds a state the definition doesn't know
     */
    public function __construct(
        public readonly Definition $definition,
        ?StateStore $store = null,
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
        $from = $this->state;

        if (!$this->definition->hasEvent($event)) {
            throw UnknownEventException::named($event);
        }

        $transition = $this->select($event, $from, $payload);

        if ($transition === null) {
            throw array_any(
                $this->definition->transitionsFor($event),
                static fn(Transition $t): bool => $t->leavesFrom($from),
            )
                ? GuardRejectedException::forEvent($event, $from)
                : TransitionNotAllowedException::fromState($event, $from);
        }

        $context = $transition->contextFor($from, $payload);

        foreach ([...$transition->before, ...$this->definition->exitHooksFor($from)] as $hook) {
            $hook->handle($context);
        }

        // A `before`/exit hook may have sent another event; don't overwrite that with a stale transition.
        if ($this->state !== $from) {
            throw ConcurrentTransitionException::forEvent($event, $from, $this->state);
        }

        $this->write($event, $from, $transition->to);

        try {
            foreach ([...$this->definition->enterHooksFor($transition->to), ...$transition->after] as $hook) {
                $hook->handle($context);
            }
        } catch (Throwable $e) {
            throw new AfterHookFailedException($context, $e);
        }

        return $this;
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
