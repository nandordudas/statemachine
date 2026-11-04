<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * An immutable edge of the graph: `$event` moves the machine from any of `$from` to `$to`.
 *
 * Guards, `before` and `after` hooks are added with the `with*` methods, each returning a new instance:
 *
 *     Transition::on('PLAY', from: ['stopped', 'paused'], to: 'playing')
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

    /**
     * @param string|BackedEnum|non-empty-list<string|BackedEnum> $from
     * @param list<Guard|callable(TransitionContext): bool> $guards all must allow the transition
     * @param list<Hook|callable(TransitionContext): mixed> $before run before the state changes; throwing aborts the transition
     * @param list<Hook|callable(TransitionContext): mixed> $after run once the new state is stored
     */
    public function __construct(
        string|BackedEnum $event,
        string|BackedEnum|array $from,
        string|BackedEnum $to,
        array $guards = [],
        array $before = [],
        array $after = [],
    ) {
        $this->event = Name::of($event);
        $this->to = Name::of($to);
        $sources = Name::listOf($from);

        if ($this->event === '' || $this->to === '' || $sources === [] || in_array('', $sources, true)) {
            throw new InvalidDefinitionException('A transition needs a non-empty event, source state(s) and target state.');
        }

        $this->from = $sources;
        $this->guards = array_map(ClosureGuard::wrap(...), array_values($guards));
        $this->before = array_map(ClosureHook::wrap(...), array_values($before));
        $this->after = array_map(ClosureHook::wrap(...), array_values($after));
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

    public function leavesFrom(string $state): bool
    {
        return in_array($state, $this->from, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function contextFor(string $from, array $payload = []): TransitionContext
    {
        return new TransitionContext($this->event, $from, $this->to, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function permits(string $from, array $payload = []): bool
    {
        if (!$this->leavesFrom($from)) {
            return false;
        }

        $context = $this->contextFor($from, $payload);

        return array_all($this->guards, static fn(Guard $guard): bool => $guard->allows($context));
    }
}
