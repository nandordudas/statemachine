---
name: state-machine
description: Model a workflow, status lifecycle or approval flow with the nandordudas/state-machine library (PHP 8.5+). Use when the user wants states/transitions, guards, hooks, order/payment/document statuses, or to replace scattered `if ($status === ...)` checks.
---

# Modelling workflows with state-machine

## 1. Model first

List **states** (nouns: `Draft`, `Review`), **events** (verbs: `Submit`, `Approve`), and for each event its allowed
source states and target. Decide which rules are *guards* (may this happen now? pure) and which are *hooks* (do
something because it happened). Draw it: `$definition->toMermaid()`.

Use string-backed enums for states and events (`enum DocState: string`), one file each.

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
`new CallbackStore($get, $set)`. For shared rows use `AtomicCallbackStore` with `UPDATE ... WHERE status = :expected`.

## 4. Drive it

`send()` throws; `sendIfAllowed()`/`tryTransition()` swallow only refusals. Catch `StateMachineException` for all
library errors. Pass data via the payload array, read in guards/hooks through `TransitionContext`.

## Rules of thumb

- Guards: pure and cheap. Combine with `allOf`, `anyOf`, `not`, `payloadIs`, `payloadsEqual`, or a named `Guard` class
  when the rule is reused (see `examples/Document/ApproverGuard.php`).
- Side effects go in hooks. `before`/`onExit` can veto; `onEnter`/`after` run after the write and failures are wrapped
  in `AfterHookFailedException` (no rollback).
- Never call `send()` inside `before`/`onExit` (`ConcurrentTransitionException`); chaining from `onEnter`/`after` is fine.
- Check structure with `$definition->unreachableStates()`.
- Worked examples: `examples/Payment`, `examples/Document`, `examples/OrderStatus`.

## PHP 7.2 projects

Use the `php72` branch (`nandordudas/state-machine-php72`): same API with plain-string states and no named arguments.
