# AGENTS.md

Guidance for AI coding agents working on, or *using*, this library (`nandordudas/state-machine`, PHP 8.5+).
This is the `2.x` line of the Composer package `nandordudas/state-machine`. A hand-port for PHP 7.2 (no enums) lives on
branch `php72` and is the `1.x` line of the same package; Composer picks the newest line the running PHP supports.
Never add a `conflict` on `nandordudas/state-machine` or rename the package: Packagist then skips a branch.

## What this is

A small finite state machine. A `Definition` (immutable blueprint: states, initial state, transitions) is run by a
`Machine` (runtime instance) whose current state lives in a `StateStore`. States and events are strings or backed enums.

## Rules for this repository

- PHP 8.5, `declare(strict_types=1);` everywhere, `final`/`readonly` by default, PER-CS style (`composer format`).
- Only **string-backed** enums may name states/events; int-backed enums throw `InvalidDefinitionException`.
- Keep the API additive and update the README semantics table when behaviour changes.
- Every behaviour change needs a test (`tests/`); examples live in `examples/` and are checked in `tests/Examples`.
- Keep `main` and `php72` in step: a feature added here should be ported (hand-port, no enums) to `php72`.
  Tag `v2.Y.Z` here and the matching `v1.Y.Z` there.

## Using the library

```php
use function Dudas\StateMachine\{machine, on, allOf, not, payloadIs, payloadsEqual, machineFor};

$doc = machine(
    initial: DocState::Draft,
    states: DocState::class,                                   // an enum class lists all its cases
    transitions: [
        on(DocEvent::Submit, DocState::Draft, DocState::Review),
        on(DocEvent::Approve, DocState::Review, DocState::Published,
            guard: allOf(payloadIs('role', 'approver'), not(payloadsEqual('actor', 'author')))),
    ],
    onEnter: [DocState::Published->value => $notify],
);

$doc->send(DocEvent::Submit);                      // throws on refusal
$doc->sendIfAllowed(DocEvent::Approve, $payload);  // bool, never throws for a refusal
$doc->tryTransition(DocEvent::Approve, $payload);  // TransitionResult: ok(), failure, state
$flow = machineFor($definition, $entity, 'status'); // state in a string/enum-typed property
```

Pipeline of `send()`: select transition (guards, first permitted wins) → `before` hooks → `onExit` hooks → store write
(`compareAndSet` for `AtomicStateStore`) → `onEnter` hooks → `after` hooks. With a `TransactionalStateStore`
(`LockingStore`) everything up to the write runs inside its transaction. Transitions run to completion: events sent from
`onEnter`/`after` hooks are queued and drained in a loop after the current transition (bounded by `maxChainedEvents`).

| Situation | Result |
|---|---|
| Unknown event | `UnknownEventException` |
| No transition from current state | `TransitionNotAllowedException` |
| All guards veto | `GuardRejectedException` (subclass of the above; `->blockers()` lists the reasons) |
| `before`/`onExit` hook throws | aborted, state unchanged, `abort` hooks run, exception propagates |
| `send()` from a `before`/`onExit` hook or a guard, from another fiber mid-transition, or state changed concurrently | `ConcurrentTransitionException` |
| `send()` from an `onEnter`/`after` hook | queued, applied after the current transition; a cycle ends in `ChainLimitExceededException` |
| `onEnter`/`after` hook throws `Exception` | state already changed; all hooks still run; `AfterHookFailedException` (`->failures`) |

Guards must be side-effect free (they also run in `can()`/`availableEvents()`). Chain events from `onEnter`/`after`
hooks only. Use `AtomicStateStore` (`AtomicCallbackStore`, `machineFor(..., compareAndSet: ...)`) for state shared
between writers, `LockingStore` when guards read more than the state; compensate `before` side effects in `abort` hooks.
Generators: `$machine->stream($events)` yields a `TransitionResult` per event, `$machine->drive($generator)` feeds results
back into a process, `$definition->shortestPaths()` yields the events reaching each state.
Reasons: `$machine->blockers($event, $payload)` returns `list<Blocker>` (code, message, parameters), empty when allowed;
explain a guard with `because($guard, 'message', code: '...')` or implement `ExplainingGuard`; `meta['blocked']` on a
transition words a guard that gives no reason. Metadata: `meta:` on `on()`/`->withMeta()`, `->meta($state, [...])` on the
builder, `$context->meta` in guards and hooks, `availableTransitions()` for UIs, `label` shown in diagrams.
Diagnostics: `$definition->toMermaid()`, `toDot()`, `unreachableStates()`; opt-in `machine(..., history: true|N)->history()`.

## Commands

```
composer install
composer check      # phpstan (max) + phpunit
composer format     # php-cs-fixer
mago fmt --check && mago lint && mago analyze
```

CI (`.github/workflows/ci.yml`) builds and verifies both packages (this branch and `php72`).

A Claude skill for modelling workflows with this library is in `.claude/skills/state-machine/`.
