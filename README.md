# state-machine (PHP 7.2 port)

A small, strictly typed finite state machine for **PHP 7.2+**. This is the `php72` branch / package
`nandordudas/state-machine-php72`: a hand-port of the PHP 8.5 library on `main`
(`nandordudas/state-machine`), with the same concepts and nearly the same API.

Differences from the PHP 8.5 version: states and events are plain **strings** (no enums), the state is
`$machine->state()` (also readable as `$machine->state`), there are no named arguments, and value-object properties
are `@readonly` by convention instead of enforced.

## Installation

| Your PHP | Package |
|---|---|
| 7.2 to 8.4 | `nandordudas/state-machine-php72` (this package) |
| 8.5+ | [`nandordudas/state-machine`](https://github.com/nandordudas/statemachine) (enums, named arguments) |

```bash
composer require nandordudas/state-machine-php72
```

Both packages use the `Dudas\StateMachine` namespace, so install only one; this package declares a `conflict` with
the other. Until it is on Packagist, require it from the repository:

```json
{
  "repositories": [{ "type": "vcs", "url": "https://github.com/nandordudas/state-machine-php72" }],
  "require": { "nandordudas/state-machine-php72": "dev-main" }
}
```

(or a `path` repository to a local checkout). For a private repository give Composer a read-only token:
`composer config --global github-oauth.github.com <token>`.

## Quick start

```php
use Dudas\StateMachine\{Definition, Machine, Transition, TransitionContext};

$definition = Definition::builder()
    ->initial('stopped')
    ->states('playing', 'stopped', 'paused')
    ->transition(
        Transition::on('PLAY', ['stopped', 'paused'], 'playing')
            ->withGuard(function (TransitionContext $c) { return $c->payload['licensed'] ?? false; })
            ->withAfter(function (TransitionContext $c) use ($log) { $log->info("{$c->from} -> {$c->to}"); }),
        Transition::on('PAUSE', 'playing', 'paused'),
        Transition::on('STOP', 'playing', 'stopped')
    )
    ->build();

$video = new Machine($definition);
$video->send('PLAY', ['licensed' => true]);
$video->state();           // 'playing'
$video->availableEvents(); // ['PAUSE', 'STOP']
$video->can('PLAY');       // false
```

## Shorthand helpers

```php
use function Dudas\StateMachine\{machine, on, allOf, not, payloadIs, payloadsEqual, machineFor};

$doc = machine('draft', ['draft', 'review', 'published'], [
    on('SUBMIT', 'draft', 'review'),
    on('APPROVE', 'review', 'published', allOf(payloadIs('role', 'approver'), not(payloadsEqual('actor', 'author')))),
], ['published' => $notify]);                          // onEnter hooks by state

$doc->sendIfAllowed('APPROVE', $payload);              // bool, never throws for a refusal
$result = $doc->tryTransition('APPROVE', $payload);    // ->ok(), ->failure (why), ->state
$doc->sendAll(['SUBMIT', ['APPROVE', $payload]]);

$flow = machineFor($definition, $entity, 'status');    // state lives in $entity->status (string)
$definition->toMermaid();                              // stateDiagram-v2 text; toDot() for Graphviz
$definition->unreachableStates();                      // lint: states nothing can reach
```

`Definition::fromTable('draft', [['SUBMIT', 'draft', 'review'], ...])` builds a guard-less definition from rows.
Machine history is opt-in: `new Machine($definition, $store, true)` then `->history()`.

## Common patterns

```php
// State stored on your own object (a string property, public or not)
$flow = machineFor($definition, $order, 'status');

// Try without exceptions
$result = $flow->tryTransition('SHIP', $payload);   // ->ok(), ->failure, ->state

// Catch everything the library can throw
try {
    $flow->send('SHIP');
} catch (\Dudas\StateMachine\Exception\StateMachineException $e) {
    // handle
}

file_put_contents('flow.mmd', $definition->toMermaid());   // draw it
```

Guards (`withGuard`, `allOf`, ...) decide whether an event is allowed and must be side-effect free. Hooks
(`before`, `onExit`, `onEnter`, `after`) do the work. Use class constants for state and event names to get
some of the type safety enums would give.

## Semantics

`send()` runs: select transition (guards) → `before` hooks → `onExit` hooks → store write → `onEnter` hooks → `after` hooks.

| Situation | Result |
|---|---|
| Unknown event | `UnknownEventException` |
| No transition from the current state | `TransitionNotAllowedException` |
| Transition exists but every guard vetoes | `GuardRejectedException` |
| `before` or `onExit` hook throws | transition aborted, state unchanged, exception propagates |
| `send()` from a `before`/`onExit` hook, or the state changed meanwhile | `ConcurrentTransitionException` |
| `onEnter`/`after` hook throws | state already changed, **not** rolled back; `AfterHookFailedException` |
| Store holds an unknown or missing state | `UnknownStateException` |

Stores shared between writers should implement `AtomicStateStore` (`compareAndSet`); `InMemoryStore` and
`AtomicCallbackStore` do. Guards may run twice (`can()` then `send()`), so keep them side-effect free.

## Contributing

Code must stay valid PHP 7.2 (see `AGENTS.md`). Features land in the PHP 8.5 package first and are hand-ported here;
keep the two in step and tag them with the same version.

## Develop

```
composer install && composer check   # phpstan (max) + phpunit 8
mago fmt --check && mago lint && mago analyze
```

Mago has no PHP 7.2 mode (minimum 8.0), so `mago.toml` analyses as 8.0 and disables the rules that would suggest
PHP 7.3+/8.0 syntax.

## Editor and agent setup

- `.vscode/`: recommended extensions (Intelephense, Mago, PHPStan, PHPUnit test explorer, Xdebug, EditorConfig, TOML),
  format-on-save with Mago, and tasks for `composer check` and the Mago commands. `.editorconfig` sets 4-space PHP.
- `AGENTS.md` (and `CLAUDE.md` importing it): conventions and the 7.2 syntax rules for AI coding agents.
- `.claude/skills/state-machine/`: a Claude skill that teaches modelling workflows with this library.

## CI

`.github/workflows/ci.yml` builds and verifies both packages (`main` on PHP 8.5, `php72` on PHP 7.2) with Mago,
PHPStan and PHPUnit, and uploads each package as a `composer archive` zip artifact.
