<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

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
 *
 * The current state is `$machine->state()` (also readable as `$machine->state`).
 *
 * @property-read string $state
 */
final class Machine implements StateMachine
{
    /** @var Definition @readonly */
    public $definition;

    /** @var StateStore */
    private $store;

    /** @var bool */
    private $recordHistory;

    /** @var bool True while `before`/`onExit` hooks run; a nested send() then could only race with this one. */
    private $inPreHooks = false;

    /** @var TransitionContext[] */
    private $history = [];

    /**
     * @throws UnknownStateException when the store holds a state the definition doesn't know
     */
    public function __construct(Definition $definition, ?StateStore $store = null, bool $recordHistory = false)
    {
        $this->definition = $definition;
        $this->store = $store ?? new InMemoryStore($definition->initial);
        $this->recordHistory = $recordHistory;

        if (!$definition->hasState($this->state())) {
            throw UnknownStateException::named($this->state());
        }
    }

    public function state(): string
    {
        return $this->store->get();
    }

    /**
     * @return string|null
     */
    public function __get(string $name)
    {
        if ($name === 'state') {
            return $this->state();
        }

        trigger_error(sprintf('Undefined property: %s::$%s', self::class, $name), E_USER_NOTICE);

        return null;
    }

    public function __isset(string $name): bool
    {
        return $name === 'state';
    }

    public function is(string ...$states): bool
    {
        return in_array($this->state(), $states, true);
    }

    public function can(string $event, array $payload = []): bool
    {
        return $this->select($event, $this->state(), $payload) !== null;
    }

    public function availableEvents(array $payload = []): array
    {
        $from = $this->state();

        return array_values(array_filter($this->definition->events(), function (string $event) use (
            $from,
            $payload
        ): bool {
            return $this->select($event, $from, $payload) !== null;
        }));
    }

    public function send(string $event, array $payload = [])
    {
        $from = $this->guardState($event);
        $transition = $this->plan($event, $from, $payload);

        if ($transition instanceof Exception) {
            throw $transition;
        }

        $this->apply($transition, $event, $from, $payload);

        return $this;
    }

    public function sendAll(iterable $events)
    {
        foreach ($events as $item) {
            if (is_array($item)) {
                $this->send($item[0], $item[1] ?? []);
            } else {
                $this->send($item);
            }
        }

        return $this;
    }

    /**
     * Transitions applied so far, oldest first. Only recorded when the machine was created with
     * `$recordHistory = true` (it grows without bound, so it is opt-in).
     *
     * @return TransitionContext[]
     */
    public function history(): array
    {
        return $this->history;
    }

    public function sendIfAllowed(string $event, array $payload = []): bool
    {
        return $this->tryTransition($event, $payload)->ok();
    }

    public function tryTransition(string $event, array $payload = []): TransitionResult
    {
        $from = $this->guardState($event);
        $transition = $this->plan($event, $from, $payload);

        if ($transition instanceof Exception) {
            /** @var \Dudas\StateMachine\Exception\StateMachineException $transition */
            return TransitionResult::refused($from, $transition);
        }

        $this->apply($transition, $event, $from, $payload);

        return TransitionResult::succeeded($this->state());
    }

    /**
     * Preconditions that are programming errors rather than refusals, so they always throw.
     */
    private function guardState(string $event): string
    {
        if ($this->inPreHooks) {
            throw ConcurrentTransitionException::nestedSend($event);
        }

        $from = $this->state();

        if (!$this->definition->hasState($from)) {
            throw UnknownStateException::named($from);
        }

        return $from;
    }

    /**
     * @param array<string, mixed> $payload
     * @return Transition|UnknownEventException|TransitionNotAllowedException the refusal, not thrown
     */
    private function plan(string $event, string $from, array $payload)
    {
        if (!$this->definition->hasEvent($event)) {
            return UnknownEventException::named($event);
        }

        $transition = $this->select($event, $from, $payload);

        if ($transition !== null) {
            return $transition;
        }

        $leavesFromHere = Support::any($this->definition->transitionsFor($event), static function (Transition $t) use (
            $from
        ): bool {
            return $t->leavesFrom($from);
        });

        return $leavesFromHere
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
            foreach (array_merge($transition->before, $this->definition->exitHooksFor($from)) as $hook) {
                $hook->handle($context);
            }
        } finally {
            $this->inPreHooks = false;
        }

        // Another writer may have changed the state meanwhile; don't overwrite it with a stale transition.
        if ($this->state() !== $from) {
            throw ConcurrentTransitionException::forEvent($event, $from, $this->state());
        }

        $this->write($event, $from, $transition->to);

        if ($this->recordHistory) {
            $this->history[] = $context;
        }

        try {
            foreach (array_merge($this->definition->enterHooksFor($transition->to), $transition->after) as $hook) {
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
        $found = Support::find($this->definition->transitionsFor($event), static function (Transition $t) use (
            $from,
            $payload
        ): bool {
            return $t->permits($from, $payload);
        });

        return $found instanceof Transition ? $found : null;
    }
}
