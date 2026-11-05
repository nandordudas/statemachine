---
name: state-machine
description: Model a workflow, status lifecycle or approval flow with the nandordudas/state-machine (PHP 7.2 port) library. Use when the user wants states/transitions, guards, hooks, order/payment/document statuses, or to replace scattered `if ($status === ...)` checks in PHP 7.2 code.
---

# Modelling workflows with state-machine (PHP 7.2 port)

Install: `composer require nandordudas/state-machine` (this is the `1.x` line for PHP 7.2 to 8.4; PHP 8.5+ gets `2.x`
with enums). Pin `^1.0` to stay on this API.

## 1. Model first

List **states** (nouns: `draft`, `review`), **events** (verbs: `SUBMIT`, `APPROVE`), and for each event its allowed
source states and target. Decide which rules are *guards* (may this happen now? pure) and which are *hooks* (do
something because it happened). Draw it: `$definition->toMermaid()`.

## 2. Build

States/events are strings; name them with class constants (one class for states, one for events), never
string literals.

```php
final class DocState { const DRAFT = 'draft'; const REVIEW = 'review'; const PUBLISHED = 'published'; }
final class DocEvent { const SUBMIT = 'SUBMIT'; const APPROVE = 'APPROVE'; }

$definition = Definition::builder()
    ->initial(DocState::DRAFT)
    ->states(...Name::constantsOf(DocState::class))   // every state, read once from the class
    ->onEnter(DocState::PUBLISHED, $notify)          // after the state is stored
    ->onExit(DocState::REVIEW, $clearReviewer)       // before leaving; throwing aborts
    ->transition(
        Transition::on(DocEvent::SUBMIT, DocState::DRAFT, DocState::REVIEW),
        Transition::on(DocEvent::APPROVE, DocState::REVIEW, DocState::PUBLISHED)->withGuard($isApprover)
    )
    ->build();
```

Shorthand: `machine(DocState::DRAFT, DocState::class, [on(...)], [DocState::PUBLISHED => $hook])`; guard-less:
`Definition::fromTable()`.
Several transitions with the same event and source = conditional branching; **first permitted wins**, so put the
guarded one first.

## 3. Bind state to the entity

`machineFor($definition, $entity, 'status')` (any string property, public or not) or `new CallbackStore($get, $set)`.
For shared rows use `AtomicCallbackStore` with `UPDATE ... WHERE status = :expected`.

## 4. Drive it

`send()` throws; `sendIfAllowed()`/`tryTransition()` swallow only refusals. Catch `StateMachineException` for all
library errors. Pass data via the payload array, read in guards/hooks through `TransitionContext`.

## Rules of thumb

- Guards: pure and cheap (also run by `can()`/`availableEvents()`). Combine with `allOf`, `anyOf`, `not`, `payloadIs`.
- Side effects go in hooks. `before`/`onExit` can veto; `onEnter`/`after` run after the write and failures are wrapped
  in `AfterHookFailedException` (no rollback).
- Never call `send()` inside `before`/`onExit` (`ConcurrentTransitionException`); chaining from `onEnter`/`after` is fine.
- Check structure with `$definition->unreachableStates()`.

## Writing code for this branch

Must stay valid PHP 7.2 (no arrow functions, typed properties, enums, named args, union types). See `AGENTS.md`.
Test every change; run `composer check` and `mago lint`.
