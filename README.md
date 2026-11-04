# state-machine

A small, strictly typed finite state machine for PHP 8.5. A modern rewrite of
[mouadziani/xstate](https://github.com/mouadziani/xstate).

```php
use Dudas\StateMachine\{Definition, Machine, Transition, TransitionContext};

$definition = Definition::builder()
    ->initial('stopped')
    ->states('playing', 'stopped', 'paused')
    ->transition(
        Transition::on('PLAY', from: ['stopped', 'paused'], to: 'playing')
            ->withGuard(fn (TransitionContext $c) => $c->payload['licensed'] ?? false)
            ->withAfter(fn (TransitionContext $c) => $log->info("{$c->from} -> {$c->to}")),
        Transition::on('PAUSE', 'playing', 'paused'),
        Transition::on('STOP', 'playing', 'stopped'),
    )
    ->build();

$video = new Machine($definition);
$video->send('PLAY', ['licensed' => true]);
$video->state;             // 'playing'
$video->availableEvents(); // ['PAUSE', 'STOP']
$video->can('PLAY');       // false
```

## Shorthand helpers

```php
use function Dudas\StateMachine\{machine, on};

$doc = machine(
    initial: S::Draft,
    states: S::class,                                  // an enum class lists all its cases
    transitions: [
        on(E::Submit, S::Draft, S::Review),
        on(E::Approve, S::Review, S::Published, guard: new ApproverGuard(), after: $audit),
    ],
    onEnter: [S::Published->value => $notify],
);

$doc->sendIfAllowed(E::Approve, $payload);             // bool, never throws for a refusal
$result = $doc->tryTransition(E::Approve, $payload);   // ->ok(), ->failure (why), ->state
```

`Definition::fromTable($initial, [[E::Submit, S::Draft, S::Review], ...])` builds a guard-less definition
from plain rows. `sendIfAllowed`/`tryTransition` only absorb refusals (unknown event, not allowed, guard veto);
hook and concurrency failures still throw.

### More sugar

```php
use function Dudas\StateMachine\{allOf, not, payloadIs, payloadsEqual, machineFor};

// guards as values: no one-off class for simple rules
on(E::Approve, S::Review, S::Published,
    guard: allOf(payloadIs('role', 'approver'), not(payloadsEqual('actor', 'author'))));

$flow = machineFor($definition, $document);       // state lives in $document->status (string or enum typed)
$flow->sendAll([E::Submit, [E::Approve, $payload]]);

$definition->toMermaid();                         // stateDiagram-v2 text; toDot() for Graphviz
$definition->unreachableStates();                 // lint: states nothing can reach
machine(..., history: true)->history();           // opt-in log of applied transitions
```

`anyOf()` is the OR counterpart of `allOf()`. History grows without bound, so it is off by default.

## Design

- `Definition` is an immutable, validated blueprint (shareable); `Machine` is the runtime instance.
- State lives in a `StateStore` (`InMemoryStore` by default, `CallbackStore` to drive a property of an entity).
- States and events may be strings or backed enums.
- Guards (`Guard`) and hooks (`Hook`) are interfaces; closures are wrapped automatically.
- Several transitions may share event + source state; the first whose guards pass wins.
- Exceptions all implement `StateMachineException`; an invalid event/state (`TransitionNotAllowedException`)
  is distinct from a guard veto (`GuardRejectedException`, a subclass).

## Examples

- `examples/OrderStatus`: a 31-status matrix checked against legacy rules.
- `examples/Payment`: conditional branching on a gateway answer, an amount guard, an enter hook, an atomic store.
- `examples/Document`: approval workflow over an entity holding a status enum via `CallbackStore`, a named `ApproverGuard`, enter/exit hooks.

## Semantics

`send()` runs: select transition (guards) → `before` hooks → `onExit` hooks → store write → `onEnter` hooks → `after` hooks.
`onEnter`/`onExit` are per-state hooks registered on the builder (`->onEnter('playing', fn ($c) => ...)`).

| Situation | Result |
|---|---|
| Unknown event | `UnknownEventException` |
| No transition from the current state | `TransitionNotAllowedException` |
| Transition exists but every guard vetoes | `GuardRejectedException` |
| `before` or `onExit` hook throws | transition aborted, state unchanged, exception propagates |
| State changed during `before` hooks (nested `send`) | `ConcurrentTransitionException`, stale transition not applied |
| `onEnter`/`after` hook throws | state is already changed and is **not** rolled back; wrapped in `AfterHookFailedException` (original in `getPrevious()`, context in `->context`) |
| Store holds an unknown or missing state | `UnknownStateException` |

Hooks must not call `send()` from `before`/`onExit` (`ConcurrentTransitionException`); chaining from `onEnter`/`after` is fine.
Stores shared between writers should implement `AtomicStateStore` (`compareAndSet($expected, $new)`;
`InMemoryStore` and `AtomicCallbackStore` do). The machine then refuses a write that lost the race
with `ConcurrentTransitionException`; plain stores only get the check made before the write.

States and events must be strings or **string-backed** enums; int-backed enums are rejected.
Guards may run twice (`can()` then `send()`), so keep them side-effect free.

## Differences from xstate (mouadziani)

- Guards receive a `TransitionContext` (event, from, to, payload) instead of `($from, $to)`.
- `before`/`after` hooks work (the original `after()` overwrote `before`); `before` can abort.
- No `__call` magic (`$video->play()`): not statically analysable. Use `send()` with an enum.
- Definition is validated up front: undeclared states and missing initial state fail fast.

## PHP 8.5 features used (for the later downgrade)

`clone($obj, [...])` with-ers on readonly classes, `#[\NoDiscard]`, property hooks and an
interface property (`public string $state { get; }`), `array_find`/`array_any`/`array_all`,
`readonly` classes, first-class callable syntax.

## Develop

```
composer install && composer check   # phpstan (max) + phpunit
```
