---
name: state-machine
description: Model a workflow, status lifecycle or approval flow with the nandordudas/state-machine library (PHP 8.5+). Use when the user wants states/transitions, guards, hooks, order/payment/document statuses, or to replace scattered `if ($status === ...)` checks.
---

# Modelling workflows with state-machine

## 1. Model first

List **states** (nouns: `Draft`, `Review`), **events** (verbs: `Submit`, `Approve`), and for each event its allowed
source states and target. Decide which rules are *guards* (may this happen now? pure) and which are *hooks* (do
something because it happened). Draw it: `$definition->toMermaid()`.

Use string-backed enums for states and events (`enum DocState: string`), one file each. Pass the enums
everywhere; `->value` is never needed: read the state back with `$machine->stateAs(DocState::class)`, give
`machine(onEnter:/onExit:)` `[state, hook]` pairs, and seed an `InMemoryStore` with an enum.

## 2. Build

```php
$definition = Definition::builder()
    ->initial(DocState::Draft)
    ->states(...DocState::cases())
    ->onEnter(DocState::Published, $notify)          // after the state is stored
    ->onExit(DocState::Review, $clearReviewer)       // before leaving; throwing aborts
    ->transition(
        Transition::on(DocEvent::Submit, DocState::Draft, DocState::Review),
        Transition::on(DocEvent::Approve, DocState::Review, DocState::Published)->withGuard(new ApproverGuard()),
    )
    ->build();
```

Shorthand: `machine(initial:, states: Enum::class, transitions: [on(...)], onEnter: [...])`; guard-less:
`Definition::fromTable()`. Several transitions with the same event and source = conditional branching; **first
permitted wins**, so put the guarded one first.

## 3. Bind state to the entity

`machineFor($definition, $entity, 'status')` (string or enum-typed property, public or not) or
`new CallbackStore($get, $set)`. For shared rows make the write conditional: `machineFor(..., compareAndSet: fn ($old,
$new) => $repo->update(...) === 1)` or `AtomicCallbackStore` (`UPDATE ... WHERE status = :expected`, `afterWrite:` to
update the entity). When guards read other columns too, wrap the store in a `LockingStore` (`SELECT ... FOR UPDATE`).

## 4. Drive it

`send()` throws; `tryTransition()` returns refusals as a result (`->ok()`, `->failure`) and throws only for hook and
concurrency failures. Catch `StateMachineException` for all library errors. Pass data via the payload array, read in
guards/hooks through `TransitionContext` (`$context->fromAs(DocState::class)` and `toAs()` give the states as
enums). Prefer `tryTransition()` over `can()` + `send()` (racy); `sendAll()` sends several events in order.

## Rules of thumb

- Guards: pure and cheap. Combine with `allOf`, `anyOf`, `not`, `payloadIs`, `payloadsEqual`, or a named `Guard` class
  when the rule is reused (see `examples/Document/ApproverGuard.php`).
- When users need to know *why* they can't do something, explain the guards: `because($guard, 'message', code: '...')`
  or an `ExplainingGuard` class, then show `$machine->blockers($event, $payload)`. Put labels in transition `meta`.
- Side effects go in hooks. `before`/`onExit` can veto; `onEnter`/`after` run after the write and failures are wrapped
  in `AfterHookFailedException` (no rollback).
- Never call `send()` inside guards or `before`/`onExit` (`ConcurrentTransitionException`); from `onEnter`/`after` it is
  queued and applied once the current transition has finished (cycles stop at `maxChainedEvents`).
- Side effects that must not happen when the transition fails belong in `onEnter`/`after`, not in `before`/`onExit`.
- Check structure with `$definition->unreachableStates()`.
- Worked examples: `examples/Payment`, `examples/Document`, `examples/OrderStatus`.
