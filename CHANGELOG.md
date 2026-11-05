# Changelog

Format: [Keep a Changelog](https://keepachangelog.com), versions follow [semver](https://semver.org). Observable
behaviour (hook order, exception types, concurrency semantics) is part of the API.

## [Unreleased] (3.0.0)

### Changed (breaking compared to 2.0.0)
- Events sent from `onEnter`/`after` hooks are queued and applied after the current transition, inside the outer
  `send()` (run to completion) instead of nested; `maxChainedEvents` bounds chains.
- `Machine`, `TransitionContext`, `TransitionResult`, `Transition` and `GuardRejectedException` constructors and
  factories gained parameters (history size, chain limit, metadata, blockers).
- A self-loop (`from === to`) no longer writes to the store.
- `AfterHookFailedException` runs every remaining hook and carries all failures.

### Added
- Blockers (`Machine::blockers()`, `because()`, `Blocker`), transition metadata, `LockingStore` and
  `TransactionalStateStore`, `PropertyStore`, `Machine::stateAs()`, bounded history, diagrams and
  `unreachableStates()`.
- `Exception\TransitionApplied` marker (applied, do not retry), `ConcurrentTransitionException::$retryable`,
  `TransitionContext::fromAs()/toAs()`.
- `LICENSE`, `SECURITY.md`, `CONTRIBUTING.md`, `.gitattributes` (lean install archive), `extra.branch-alias`.

### Removed (to keep the library small; each has a short replacement)
- `Machine::sendIfAllowed()`: use `tryTransition($event)->ok()`.
- `Machine::stream()` and `Machine::drive()`: loop over your events and call `tryTransition()`.
- `Machine::availableTransitions()`: use `availableEvents()`, and `$definition->transitionsFrom()` for metadata.
- Abort hooks (`Transition::withAbort()`, `on(abort:)`): do side effects in `onEnter`/`after` hooks, or run the
  transition in a `LockingStore` transaction so a failure rolls back.
- State metadata (`DefinitionBuilder::meta()`, `Definition::metaFor()`, `machine(stateMeta:)`) and state labels in
  diagrams; transition metadata stays.
- The `meta['blocked']` fallback message: wrap the guard in `because()` instead.
- `Definition::shortestPaths()` (internal to `unreachableStates()` now) and `machine(maxChainedEvents:)` (pass it to
  `new Machine(...)`).
- The PHP 7.2 line (`php72` branch, `1.x`) is no longer maintained.

### Fixed
- A refused event is explained in the same pass, under the same lock, that refused it.
- `AtomicCallbackStore` forgets its remembered write after a lost race (a worker could stay wedged).
- `PropertyStore` reports an uninitialized typed property as `UnknownStateException`.
- `machine(states: ...)` rejects a class that is not a backed enum.

### Documented
- Read-then-write stores (`PropertyStore`, `CallbackStore`) are for a single owner; reload the entity before retrying a
  lost race; a failed transaction does not roll the entity back; Swoole coroutines are not fibers.

## [2.0.0]
Initial release of the PHP 8.5 line.

## [1.0.0]
Initial release of the PHP 7.2 line (branch `php72`).
