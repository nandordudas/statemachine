<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Closure;
use Dudas\StateMachine\Contracts\ExplainingGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Contracts\Hook;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Guards\AllOf;
use Dudas\StateMachine\Guards\AnyOf;
use Dudas\StateMachine\Guards\Because;
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
 * @param array<string, mixed> $meta labels, required roles, anything a UI or hook needs (see {@see Transition::$meta})
 */
function on(
    string|BackedEnum $event,
    string|BackedEnum|array $from,
    string|BackedEnum $to,
    Guard|callable|array $guard = [],
    Hook|callable|array $before = [],
    Hook|callable|array $after = [],
    array $meta = [],
): Transition {
    return new Transition($event, $from, $to, listOf($guard), listOf($before), listOf($after), $meta);
}

/**
 * Builds a ready-to-use {@see Machine} in one call.
 *
 *     $video = machine(
 *         initial: S::Stopped,
 *         states: S::class,                       // or a list; an enum class lists all its cases
 *         transitions: [on(E::Play, S::Stopped, S::Playing)],
 *         onEnter: [[S::Playing, $log]],          // [state, hook(s)] pairs: no ->value needed
 *     );
 *
 * `onEnter` and `onExit` take `[state, hook(s)]` pairs, so a state can be an enum (PHP array keys cannot be).
 * A map keyed by state name (`['playing' => $log]`) still works. Pairs for the same state are merged.
 * For a chain limit other than 100, build the {@see Machine} directly.
 *
 * @param class-string<BackedEnum>|list<string|BackedEnum> $states
 * @param list<Transition> $transitions
 * @param array<array{BackedEnum, mixed}|mixed> $onEnter hooks by state: `[state, hook|list<hook>]` pairs or a map
 * @param array<array{BackedEnum, mixed}|mixed> $onExit hooks by state: `[state, hook|list<hook>]` pairs or a map
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
): Machine {
    if (is_string($states)) {
        if (!enum_exists($states) || !is_subclass_of($states, BackedEnum::class)) {
            throw new InvalidDefinitionException(sprintf(
                '"%s" is not a backed enum; pass a list of states instead.',
                $states,
            ));
        }

        $states = $states::cases();
    }

    return new Machine(
        new Definition(
            $initial,
            $states,
            $transitions,
            hooksByState($onEnter),
            hooksByState($onExit),
        ),
        $store,
        $history,
    );
}

/**
 * `[state, value]` pairs, or `state name => value` entries, as `[name, value]` pairs.
 *
 * @param array<mixed> $entries
 * @return list<array{string, mixed}>
 *
 * @internal
 */
function stateEntries(array $entries): array
{
    $pairs = [];

    foreach ($entries as $key => $value) {
        // A pair starts with an enum: a hook (or a list of hooks) never does, so a map entry is never mistaken.
        if (is_int($key) && is_array($value) && count($value) === 2 && ($value[0] ?? null) instanceof BackedEnum) {
            $pairs[] = [Name::of($value[0]), $value[1] ?? null];

            continue;
        }

        $pairs[] = [(string) $key, $value];
    }

    return $pairs;
}

/**
 * @param array<mixed> $entries
 * @return array<string, list<Hook|callable(TransitionContext): mixed>>
 *
 * @internal
 */
function hooksByState(array $entries): array
{
    $byState = [];

    foreach (stateEntries($entries) as [$state, $hooks]) {
        /** @var list<Hook|callable(TransitionContext): mixed> $list validated by Definition */
        $list = listOf($hooks);
        $byState[$state] = [...($byState[$state] ?? []), ...$list];
    }

    return $byState;
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
        new AtomicCallbackStore($store->get(...), $compareAndSet, static function (string $state) use ($store): void {
            $store->set($state);
        }),
        $history,
    );
}

/**
 * Guard with a reason: `$guard`, explained by `$message` when it refuses (see {@see Machine::blockers()}).
 *
 *     because(payloadIs('role', 'approver'), 'Only approvers can approve.', code: 'not_approver')
 *
 * @param Guard|callable(TransitionContext): bool $guard
 * @param string|Closure(TransitionContext): string $message
 * @param array<string, mixed> $parameters
 */
function because(
    Guard|callable $guard,
    string|Closure $message,
    string $code = 'rejected',
    array $parameters = [],
): ExplainingGuard {
    return new Because($guard, $message, $code, $parameters);
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
