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
- Blockers (`Machine::blockers()`, `because()`, `Blocker`), transition and state metadata, abort hooks, `LockingStore`
  and `TransactionalStateStore`, `PropertyStore`, `Machine::stateAs()`, `stream()`, `drive()`, bounded history,
  diagrams and `unreachableStates()`.
- `Exception\TransitionApplied` marker (applied, do not retry), `ConcurrentTransitionException::$retryable`,
  `TransitionContext::fromAs()/toAs()`.
- `LICENSE`, `SECURITY.md`, `CONTRIBUTING.md`, `.gitattributes` (lean install archive), `extra.branch-alias`.

### Fixed
- A failure before a transition started no longer re-runs the abort hooks of an earlier transition (and no longer
  keeps its payload alive).
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
