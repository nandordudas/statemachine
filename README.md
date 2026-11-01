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

## Design

- `Definition` is an immutable, validated blueprint (shareable); `Machine` is the runtime instance.
- State lives in a `StateStore` (`InMemoryStore` by default, `CallbackStore` to drive a property of an entity).
- States and events may be strings or backed enums.
- Guards (`Guard`) and hooks (`Hook`) are interfaces; closures are wrapped automatically.
- Several transitions may share event + source state; the first whose guards pass wins.
- Exceptions all implement `StateMachineException`; an invalid event/state (`TransitionNotAllowedException`)
  is distinct from a guard veto (`GuardRejectedException`, a subclass).

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
