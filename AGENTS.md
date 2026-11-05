# AGENTS.md

Guidance for AI coding agents working on, or *using*, this library. This is the **PHP 7.2 port**
(branch `php72`, the `1.x` line of `nandordudas/state-machine`); the PHP 8.5 library (`3.x`) lives on `main`.

## What this is

A small finite state machine. A `Definition` (immutable blueprint: states, initial state, transitions) is run by a
`Machine` (runtime instance) whose current state lives in a `StateStore`.

## Hard rules for this branch (PHP 7.2)

- Code must parse as PHP 7.2: no typed properties, arrow functions, union types, `mixed`, `static` return types,
  named arguments, `match`, enums, `readonly`, constructor promotion, `??=`, spread of string keys, `str_*` PHP 8 helpers.
- States and events are **plain strings** (use class constants for type safety). Passing anything else throws
  `InvalidDefinitionException`. Documentation names them with class constants (`S::DRAFT`), never string literals;
  `Name::constantsOf(S::class)` (also `machine($initial, S::class, ...)`) lists a constants class, like an enum's
  cases on `main`. Tests may use literals.
- The current state is `$machine->state()` (also readable as `$machine->state`).
- Public properties on value objects are `@readonly` by convention; never assign to them.
- `declare(strict_types=1);` in every file. Add tests for every behaviour change.

## Using the library

```php
use App\{DocEvent as E, DocState as S};   // final classes of string constants (no enums on PHP 7.2)
use function Dudas\StateMachine\{machine, on, allOf, not, payloadIs, payloadsEqual};

$doc = machine(S::DRAFT, S::class, [        // a constants class lists every state
    on(E::SUBMIT, S::DRAFT, S::REVIEW),
    on(E::APPROVE, S::REVIEW, S::PUBLISHED, allOf(payloadIs('role', 'approver'), not(payloadsEqual('actor', 'author')))),
], [S::PUBLISHED => $notify]);              // onEnter hooks by state

$doc->send(E::SUBMIT);                      // throws on refusal
$doc->sendIfAllowed(E::APPROVE, $p);        // bool, never throws for a refusal
$doc->tryTransition(E::APPROVE, $p);        // TransitionResult: ok(), failure, state
```

Pipeline of `send()`: select transition (guards, first permitted wins) → `before` hooks → `onExit` hooks → store write
(`compareAndSet` for `AtomicStateStore`) → `onEnter` hooks → `after` hooks.

| Situation | Result |
|---|---|
| Unknown event | `UnknownEventException` |
| No transition from current state | `TransitionNotAllowedException` |
| All guards veto | `GuardRejectedException` (subclass of the above) |
| `before`/`onExit` hook throws | aborted, state unchanged, exception propagates |
| `send()` from a `before`/`onExit` hook, or state changed concurrently | `ConcurrentTransitionException` |
| `onEnter`/`after` hook throws `Exception` | state already changed; wrapped in `AfterHookFailedException` |

Guards must be side-effect free (they run in `can()` and `availableEvents()` too). Chain events from `onEnter`/`after`
hooks only. Use `AtomicStateStore` (`AtomicCallbackStore`) for state shared between writers.

## Packaging

One Composer package, `nandordudas/state-machine`, registered on Packagist from this repository. Two version lines:
`1.x` is this branch (`php >=7.2`), `3.x` is `main` (`php >=8.5`); Composer picks the newest one the running PHP
supports. Do **not** add a `conflict` on `nandordudas/state-machine` (a package cannot conflict with itself and
Packagist then skips the branch) and do not rename the package. Tag releases `v1.Y.Z` here when `main` is tagged
`v3.Y.Z` (bug fixes only; features stay on 3.x).

## Commands

```
composer install
composer check      # phpstan + phpunit
mago fmt --check && mago lint && mago analyze   # Mago has no 7.2 mode: configured as 8.0 with 7.3+/8.0-only rules off
composer format     # php-cs-fixer
```

CI (`.github/workflows/ci.yml`) builds and verifies both packages (this branch and `main`).

A Claude skill for modelling workflows with this library is in `.claude/skills/state-machine/`.
