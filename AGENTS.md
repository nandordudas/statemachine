# AGENTS.md

Guidance for AI coding agents working on, or *using*, this library (`nandordudas/state-machine`, PHP 8.5+, `3.x`).
Never add a `conflict` on `nandordudas/state-machine` or rename the package.

## What this is

A small finite state machine. A `Definition` (immutable blueprint: states, initial state, transitions) is run by a
`Machine` (runtime instance) whose current state lives in a `StateStore`. States and events are strings or backed enums.

## Rules for this repository

- PHP 8.5, `declare(strict_types=1);` everywhere, `final`/`readonly` by default, PER-CS style (`composer format`).
- Only **string-backed** enums may name states/events; int-backed enums throw `InvalidDefinitionException`.
- Documentation and `examples/` name states and events with enums, never string literals, and do not write
  `->value` where the API takes an enum (`stateAs()`, `InMemoryStore`, `[state, hook]` pairs in `machine()`).
  Tests may use strings: they check that plain names keep working. Read `SomeEnum::cases()` once into a variable
  when it is used more than once.
- Keep the library small: one way to do a thing. Add API only for a need the existing API cannot meet, and update
  the README semantics table when behaviour changes. Public API changes only in a major version (`CHANGELOG.md`).
- Every behaviour change needs a test (`tests/`); examples live in `examples/` and are checked in `tests/Examples`.

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
    onEnter: [[DocState::Published, $notify]],                 // [state, hook(s)] pairs: no ->value
);

$doc->send(DocEvent::Submit);                      // throws on refusal
$doc->tryTransition(DocEvent::Approve, $payload);  // TransitionResult: ok(), failure, state (refusals don't throw)
$flow = machineFor($definition, $entity, 'status'); // state in a string/enum-typed property
$doc->stateAs(DocState::class);                    // the current state as the enum ($doc->state is its name)
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
| `before`/`onExit` hook throws | aborted, state unchanged, exception propagates |
| `send()` from a `before`/`onExit` hook or a guard, from another fiber mid-transition, or state changed concurrently | `ConcurrentTransitionException` |
| `send()` from an `onEnter`/`after` hook | queued, applied after the current transition; a cycle ends in `ChainLimitExceededException` |
| `onEnter`/`after` hook throws `Exception` | state already changed; all hooks still run; `AfterHookFailedException` (`->failures`) |

Guards must be side-effect free (they also run in `can()`/`availableEvents()`). Chain events from `onEnter`/`after`
hooks only. Use `AtomicStateStore` (`AtomicCallbackStore`, `machineFor(..., compareAndSet: ...)`) for state shared
between writers, `LockingStore` when guards read more than the state; keep side effects in `onEnter`/`after` hooks.
Reasons: `$machine->blockers($event, $payload)` returns `list<Blocker>` (code, message, parameters), empty when allowed;
explain a guard with `because($guard, 'message', code: '...')` or implement `ExplainingGuard`. Transition metadata:
`meta:` on `on()`/`->withMeta()`, `$context->meta` in guards and hooks, `label` shown in diagrams.
Diagnostics: `$definition->toMermaid()`, `toDot()`, `unreachableStates()`; opt-in `machine(..., history: true|N)->history()`.

## Commands

```
composer install
composer check      # phpstan (max) + phpunit
composer format     # php-cs-fixer
mago fmt --check && mago lint && mago analyze
```

CI (`.github/workflows/ci.yml`) runs Mago, PHPStan, PHPUnit (also against MySQL) and the benchmark, and builds the
package archive.

A Claude skill for modelling workflows with this library is in `.claude/skills/state-machine/`.
