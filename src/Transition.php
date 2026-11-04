<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * An immutable edge of the graph: `$event` moves the machine from any of `$from` to `$to`.
 *
 * Guards, `before` and `after` hooks are added with the `with*` methods, each returning a new instance:
 *
 *     Transition::on('PLAY', ['stopped', 'paused'], 'playing')
 *         ->withGuard(function (TransitionContext $c) { return $c->payload['user'] !== null; })
 *         ->withAfter(function (TransitionContext $c) use ($logger) { $logger->info($c->event); });
 *
 * Properties are public for reading and must not be modified (PHP 7.2 has no readonly).
 */
final class Transition
{
    /** @var string @readonly */
    public $event;

    /** @var string[] @readonly non-empty */
    public $from;

    /** @var string @readonly */
    public $to;

    /** @var Guard[] @readonly all must allow the transition */
    public $guards;

    /** @var Hook[] @readonly run before the state changes; throwing aborts the transition */
    public $before;

    /** @var Hook[] @readonly run once the new state is stored */
    public $after;

    /**
     * @param string|string[] $from
     * @param array<Guard|callable> $guards
     * @param array<Hook|callable> $before
     * @param array<Hook|callable> $after
     */
    public function __construct(
        string $event,
        $from,
        string $to,
        array $guards = [],
        array $before = [],
        array $after = []
    ) {
        $sources = Name::listOf($from);

        if ($event === '' || $to === '' || $sources === [] || in_array('', $sources, true)) {
            throw new InvalidDefinitionException(
                'A transition needs a non-empty event, source state(s) and target state.'
            );
        }

        $this->event = $event;
        $this->from = $sources;
        $this->to = $to;
        $this->guards = array_map([ClosureGuard::class, 'wrap'], array_values($guards));
        $this->before = array_map([ClosureHook::class, 'wrap'], array_values($before));
        $this->after = array_map([ClosureHook::class, 'wrap'], array_values($after));
    }

    /**
     * @param string|string[] $from
     */
    public static function on(string $event, $from, string $to): self
    {
        return new self($event, $from, $to);
    }

    /**
     * @param Guard|callable(TransitionContext): bool $guard
     */
    public function withGuard($guard): self
    {
        $copy = clone $this;
        $copy->guards[] = ClosureGuard::wrap($guard);

        return $copy;
    }

    /**
     * @param Hook|callable(TransitionContext): mixed $hook
     */
    public function withBefore($hook): self
    {
        $copy = clone $this;
        $copy->before[] = ClosureHook::wrap($hook);

        return $copy;
    }

    /**
     * @param Hook|callable(TransitionContext): mixed $hook
     */
    public function withAfter($hook): self
    {
        $copy = clone $this;
        $copy->after[] = ClosureHook::wrap($hook);

        return $copy;
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

        return Support::all($this->guards, static function (Guard $guard) use ($context): bool {
            return $guard->allows($context);
        });
    }
}
