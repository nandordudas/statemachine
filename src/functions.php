<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Closure;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Guards\AllOf;
use Dudas\StateMachine\Guards\AnyOf;
use Dudas\StateMachine\Guards\Not;
use Dudas\StateMachine\Guards\PayloadIs;
use Dudas\StateMachine\Guards\PayloadsEqual;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\Store\PropertyStore;

/**
 * Shorthand for {@see Transition}: one call instead of `Transition::on()->withGuard()->withAfter()`.
 *
 *     on(E::Approve, from: S::Review, to: S::Published, guard: new ApproverGuard(), after: $audit)
 *
 * `guard`, `before` and `after` take a single guard/hook/closure or a list of them.
 *
 * @param string|BackedEnum|non-empty-list<string|BackedEnum> $from
 * @param Guard|callable(TransitionContext): bool|list<Guard|callable(TransitionContext): bool> $guard
 * @param Hook|callable(TransitionContext): mixed|list<Hook|callable(TransitionContext): mixed> $before
 * @param Hook|callable(TransitionContext): mixed|list<Hook|callable(TransitionContext): mixed> $after
 * @param Hook|callable(TransitionContext): mixed|list<Hook|callable(TransitionContext): mixed> $abort
 */
function on(
    string|BackedEnum $event,
    string|BackedEnum|array $from,
    string|BackedEnum $to,
    Guard|callable|array $guard = [],
    Hook|callable|array $before = [],
    Hook|callable|array $after = [],
    Hook|callable|array $abort = [],
): Transition {
    return new Transition($event, $from, $to, listOf($guard), listOf($before), listOf($after), listOf($abort));
}

/**
 * Builds a ready-to-use {@see Machine} in one call.
 *
 *     $video = machine(
 *         initial: S::Stopped,
 *         states: S::class,                       // or a list; an enum class lists all its cases
 *         transitions: [on(E::Play, S::Stopped, S::Playing)],
 *         onEnter: [S::Playing->value => $log],   // hooks by state name
 *     );
 *
 * @param class-string<BackedEnum>|list<string|BackedEnum> $states
 * @param list<Transition> $transitions
 * @param array<string, Hook|callable(TransitionContext): mixed|list<Hook|callable(TransitionContext): mixed>> $onEnter
 * @param array<string, Hook|callable(TransitionContext): mixed|list<Hook|callable(TransitionContext): mixed>> $onExit
 * @param bool|int $history true records every transition, a positive int only the latest N
 */
function machine(
    string|BackedEnum $initial,
    string|array $states,
    array $transitions,
    array $onEnter = [],
    array $onExit = [],
    ?StateStore $store = null,
    bool|int $history = false,
    int $maxChainedEvents = 100,
): Machine {
    $states = is_string($states) ? $states::cases() : $states;

    return new Machine(
        new Definition(
            $initial,
            $states,
            $transitions,
            array_map(listOf(...), $onEnter),
            array_map(listOf(...), $onExit),
        ),
        $store,
        $history,
        $maxChainedEvents,
    );
}

/**
 * @template T
 * @param T|list<T> $value
 * @return list<T>
 *
 * @internal
 */
function listOf(mixed $value): array
{
    return is_array($value) ? array_values($value) : [$value];
}

/**
 * A machine whose state lives in a property of `$entity` (string or backed enum typed).
 *
 *     $flow = machineFor($definition, $document);            // property 'status'
 *     $flow = machineFor($definition, $order, 'stage');
 *
 * A plain property is not safe when several processes load the same entity. Pass `$compareAndSet`
 * (e.g. `UPDATE ... SET status = :new WHERE id = :id AND status = :expected`) to make the write
 * conditional; on success the property is updated too.
 *
 *     $flow = machineFor($definition, $order, compareAndSet:
 *         fn (string $expected, string $new): bool => $repo->updateStatus($order->id, $expected, $new) === 1);
 *
 * @param (Closure(string, string): bool)|null $compareAndSet receives (expected, new); returns whether it wrote
 */
function machineFor(
    Definition $definition,
    object $entity,
    string $property = 'status',
    bool|int $history = false,
    ?Closure $compareAndSet = null,
): Machine {
    $store = new PropertyStore($entity, $property);
    $store->assertCanHold($definition->states);

    if ($compareAndSet === null) {
        return new Machine($definition, $store, $history);
    }

    return new Machine(
        $definition,
        new AtomicCallbackStore(static fn(): string => $store->get(), $compareAndSet, static function (string $state) use (
            $store,
        ): void {
            $store->set($state);
        }),
        $history,
    );
}

/** Guard: every inner guard allows. */
function allOf(Guard|callable ...$guards): Guard
{
    return new AllOf(...$guards);
}

/** Guard: at least one inner guard allows. */
function anyOf(Guard|callable ...$guards): Guard
{
    return new AnyOf(...$guards);
}

/** Guard: the inner guard does not allow. */
function not(Guard|callable $guard): Guard
{
    return new Not($guard);
}

/** Guard: `payload[$key] === $expected`. */
function payloadIs(string $key, mixed $expected): Guard
{
    return new PayloadIs($key, $expected);
}

/** Guard: `payload[$a] === payload[$b]`. */
function payloadsEqual(string $a, string $b): Guard
{
    return new PayloadsEqual($a, $b);
}
