<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

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
 *     on('APPROVE', 'review', 'published', new ApproverGuard(), [], $audit)
 *
 * `$guard`, `$before` and `$after` take a single guard/hook/callable or a list of them.
 *
 * @param string|string[] $from
 * @param Guard|callable|array $guard
 * @param Hook|callable|array $before
 * @param Hook|callable|array $after
 */
function on(string $event, $from, string $to, $guard = [], $before = [], $after = []): Transition
{
    return new Transition($event, $from, $to, listOf($guard), listOf($before), listOf($after));
}

/**
 * Builds a ready-to-use {@see Machine} in one call.
 *
 *     $video = machine('stopped', ['playing', 'stopped'], [on('PLAY', 'stopped', 'playing')]);
 *
 * @param string[] $states
 * @param Transition[] $transitions
 * @param array<string, Hook|callable|array> $onEnter hooks by state name
 * @param array<string, Hook|callable|array> $onExit hooks by state name
 */
function machine(
    string $initial,
    array $states,
    array $transitions,
    array $onEnter = [],
    array $onExit = [],
    ?StateStore $store = null,
    bool $history = false
): Machine {
    return new Machine(
        new Definition(
            $initial,
            $states,
            $transitions,
            array_map(__NAMESPACE__ . '\\listOf', $onEnter),
            array_map(__NAMESPACE__ . '\\listOf', $onExit)
        ),
        $store,
        $history
    );
}

/**
 * A machine whose state lives in a (string) property of `$entity`.
 */
function machineFor(Definition $definition, object $entity, string $property = 'status', bool $history = false): Machine
{
    return new Machine($definition, new PropertyStore($entity, $property), $history);
}

/**
 * @param Guard|callable(TransitionContext): bool ...$guards
 */
function allOf(...$guards): Guard
{
    return new AllOf(...$guards);
}

/**
 * @param Guard|callable(TransitionContext): bool ...$guards
 */
function anyOf(...$guards): Guard
{
    return new AnyOf(...$guards);
}

/**
 * @param Guard|callable(TransitionContext): bool $guard
 */
function not($guard): Guard
{
    return new Not($guard);
}

/**
 * @param mixed $expected
 */
function payloadIs(string $key, $expected): Guard
{
    return new PayloadIs($key, $expected);
}

function payloadsEqual(string $a, string $b): Guard
{
    return new PayloadsEqual($a, $b);
}

/**
 * @param mixed $value
 * @return array<mixed>
 *
 * @internal
 */
function listOf($value): array
{
    return is_array($value) ? array_values($value) : [$value];
}
