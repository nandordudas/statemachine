<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Guards\Explain;

/**
 * An immutable edge of the graph: `$event` moves the machine from any of `$from` to `$to`.
 *
 * Guards, `before` and `after` hooks are added with the `with*` methods, each returning a new instance:
 *
 *     Transition::on(E::Play, from: [S::Stopped, S::Paused], to: S::Playing)
 *         ->withGuard(fn (TransitionContext $c) => $c->payload['user'] !== null)
 *         ->withAfter(fn (TransitionContext $c) => $logger->info($c->event));
 */
final readonly class Transition
{
    public string $event;

    /** @var non-empty-list<string> */
    public array $from;

    public string $to;

    /** @var list<Guard> */
    public array $guards;

    /** @var list<Hook> */
    public array $before;

    /** @var list<Hook> */
    public array $after;

    /** @var list<Hook> run when the transition is aborted after its `before` hooks started */
    public array $abort;

    /**
     * @var array<string, mixed> free-form data for UIs, guards and diagrams. Known keys: `label` (shown in
     *      diagrams) and `blocked` (the message of a guard that refuses without explaining why).
     */
    public array $meta;

    /**
     * @param string|BackedEnum|non-empty-list<string|BackedEnum> $from
     * @param array<Guard|callable(TransitionContext): bool> $guards all must allow the transition
     * @param array<Hook|callable(TransitionContext): mixed> $before run before the state changes; throwing aborts the transition
     * @param array<Hook|callable(TransitionContext): mixed> $after run once the new state is stored
     * @param array<Hook|callable(TransitionContext): mixed> $abort run when a `before`/`onExit` hook threw or the
     *                                                             write lost a race: undo their side effects here
     * @param array<string, mixed> $meta see {@see $meta}
     */
    public function __construct(
        string|BackedEnum $event,
        string|BackedEnum|array $from,
        string|BackedEnum $to,
        array $guards = [],
        array $before = [],
        array $after = [],
        array $abort = [],
        array $meta = [],
    ) {
        $this->event = Name::of($event);
        $this->to = Name::of($to);
        $sources = Name::listOf($from);

        if ($this->event === '' || $this->to === '' || $sources === [] || in_array('', $sources, true)) {
            throw new InvalidDefinitionException(
                'A transition needs a non-empty event, source state(s) and target state.',
            );
        }

        $this->from = $sources;
        $this->guards = array_map(ClosureGuard::wrap(...), array_values($guards));
        $this->before = array_map(ClosureHook::wrap(...), array_values($before));
        $this->after = array_map(ClosureHook::wrap(...), array_values($after));
        $this->abort = array_map(ClosureHook::wrap(...), array_values($abort));
        $this->meta = $meta;
    }

    /**
     * @param string|BackedEnum|non-empty-list<string|BackedEnum> $from
     */
    public static function on(string|BackedEnum $event, string|BackedEnum|array $from, string|BackedEnum $to): self
    {
        return new self($event, $from, $to);
    }

    /**
     * @param Guard|callable(TransitionContext): bool $guard
     */
    #[\NoDiscard]
    public function withGuard(Guard|callable $guard): self
    {
        return clone($this, ['guards' => [...$this->guards, ClosureGuard::wrap($guard)]]);
    }

    /**
     * @param Hook|callable(TransitionContext): mixed $hook
     */
    #[\NoDiscard]
    public function withBefore(Hook|callable $hook): self
    {
        return clone($this, ['before' => [...$this->before, ClosureHook::wrap($hook)]]);
    }

    /**
     * @param Hook|callable(TransitionContext): mixed $hook
     */
    #[\NoDiscard]
    public function withAfter(Hook|callable $hook): self
    {
        return clone($this, ['after' => [...$this->after, ClosureHook::wrap($hook)]]);
    }

    /**
     * Compensation for side effects of `before`/`onExit` hooks when the transition does not happen after all.
     * The original failure still propagates (unless an abort hook throws, which replaces it).
     *
     * @param Hook|callable(TransitionContext): mixed $hook
     */
    #[\NoDiscard]
    public function withAbort(Hook|callable $hook): self
    {
        return clone($this, ['abort' => [...$this->abort, ClosureHook::wrap($hook)]]);
    }

    /**
     * Adds (or overrides) metadata keys.
     *
     * @param array<string, mixed> $meta
     */
    #[\NoDiscard]
    public function withMeta(array $meta): self
    {
        return clone($this, ['meta' => [...$this->meta, ...$meta]]);
    }

    /**
     * Why the guards refuse this transition from `$from`; empty when it is allowed (or there are no guards).
     * Unlike {@see permits()} it asks every guard, so all reasons are listed. Does not check `$from`.
     *
     * @param array<string, mixed> $payload
     * @return list<Blocker>
     */
    public function blockers(string $from, array $payload = []): array
    {
        if ($this->guards === []) {
            return [];
        }

        $context = $this->contextFor($from, $payload);
        $blockers = [];

        foreach ($this->guards as $guard) {
            array_push($blockers, ...Explain::blockers($guard, $context));
        }

        $message = $this->meta['blocked'] ?? null;

        if (!is_string($message)) {
            return $blockers;
        }

        // A guard that gave no reason gets the transition's own message, so guards can stay generic.
        return array_map(static fn(Blocker $b): Blocker => $b->code === Blocker::GUARD_REJECTED
            ? new Blocker($b->code, $message, $b->parameters)
            : $b, $blockers);
    }

    public function leavesFrom(string $state): bool
    {
        return in_array($state, $this->from, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function contextFor(string $from, array $payload = []): TransitionContext
    {
        return new TransitionContext($this->event, $from, $this->to, $payload, $this->meta);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function permits(string $from, array $payload = []): bool
    {
        if (!$this->leavesFrom($from)) {
            return false;
        }

        if ($this->guards === []) {
            return true; // no context to build
        }

        $context = $this->contextFor($from, $payload);

        return array_all($this->guards, static fn(Guard $guard): bool => $guard->allows($context));
    }
}
