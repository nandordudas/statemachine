<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Guards\AllOf;
use Dudas\StateMachine\Guards\AnyOf;
use Dudas\StateMachine\Guards\Not;
use Dudas\StateMachine\Guards\PayloadIs;
use Dudas\StateMachine\Guards\PayloadsEqual;
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
 */
function on(
    string|BackedEnum $event,
    string|BackedEnum|array $from,
    string|BackedEnum $to,
    Guard|callable|array $guard = [],
    Hook|callable|array $before = [],
    Hook|callable|array $after = [],
): Transition {
    return new Transition($event, $from, $to, listOf($guard), listOf($before), listOf($after));
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
 */
function machine(
    string|BackedEnum $initial,
    string|array $states,
    array $transitions,
    array $onEnter = [],
    array $onExit = [],
    ?StateStore $store = null,
    bool $history = false,
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
 */
function machineFor(Definition $definition, object $entity, string $property = 'status', bool $history = false): Machine
{
    return new Machine($definition, new PropertyStore($entity, $property), $history);
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
