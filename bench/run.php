<?php

declare(strict_types=1);

/**
 * Micro-benchmarks and memory checks: `php bench/run.php [iterations]`.
 * Prints operations per second and the memory each scenario retains after garbage collection.
 */

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\Store\LockingStore;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;

use function Dudas\StateMachine\on;

require __DIR__ . '/../vendor/autoload.php';

$n = (int) ($argv[1] ?? 50_000);

/**
 * @param Closure(int): mixed $body
 */
function bench(string $name, int $n, Closure $body, int $rounds = 5): void
{
    gc_collect_cycles();
    $body(0); // warm up
    gc_collect_cycles();
    $before = memory_get_usage();
    $best = INF;

    // Best of several rounds: shared CI runners are noisy, the fastest round is the most stable figure.
    for ($round = 0; $round < $rounds; $round++) {
        $start = hrtime(true);

        for ($i = 0; $i < $n; $i++) {
            $body($i);
        }

        $best = min($best, (hrtime(true) - $start) / 1e9);
    }

    gc_collect_cycles();
    $retained = memory_get_usage() - $before;

    printf("%-44s %12s ops/s %10.2f µs/op %+10d B retained\n", $name, number_format($n / $best), $best / $n * 1e6, $retained);
}

$toggle = Definition::fromTable('off', [['FLIP', 'off', 'on'], ['FLIP', 'on', 'off']]);

$plain = new Machine($toggle);
bench('send() toggle, in-memory', $n, static fn() => $plain->send('FLIP'));

$guarded = new Machine(new Definition('off', ['off', 'on'], [
    on('FLIP', 'off', 'on', guard: static fn(TransitionContext $c): bool => true, before: static fn() => null, after: static fn() => null),
    on('FLIP', 'on', 'off', guard: static fn(TransitionContext $c): bool => true, before: static fn() => null, after: static fn() => null),
]));
bench('send() with guard + before + after', $n, static fn() => $guarded->send('FLIP'));

$branches = [];
for ($k = 0; $k < 20; $k++) {
    $branches[] = Transition::on('GO', 'a', 'b')->withGuard(static fn(): bool => false);
}
$branches[] = Transition::on('GO', 'a', 'b');
$branches[] = Transition::on('BACK', 'b', 'a');
$branching = new Machine(new Definition('a', ['a', 'b'], $branches));
bench('send() picking 21st of 21 branches', $n, static fn() => $branching->send($branching->state === 'a' ? 'GO' : 'BACK'));

$refuser = new Machine($toggle, new InMemoryStore('off'));
bench('sendIfAllowed() refused (unknown event)', $n, static fn() => $refuser->sendIfAllowed('NOPE'));
bench('tryTransition() refused (unknown event)', $n, static fn() => $refuser->tryTransition('NOPE'));
bench('can()', $n, static fn() => $refuser->can('FLIP'));

$states = array_map(static fn(int $i): string => "s{$i}", range(0, 49));
$wide = [];
foreach ($states as $i => $s) {
    foreach ($states as $j => $t) {
        if ($i !== $j && ($i + $j) % 7 === 0) {
            $wide[] = Transition::on("E{$i}_{$j}", $s, $t);
        }
    }
}
$wideMachine = new Machine(new Definition('s0', $states, $wide));
bench('availableEvents() on ' . count($wide) . ' transitions', (int) ($n / 20), static fn() => $wideMachine->availableEvents());

$row = 'off';
$atomic = new Machine($toggle, new AtomicCallbackStore(static function () use (&$row): string {
    return $row;
}, static function (string $expected, string $new) use (&$row): bool {
    if ($row !== $expected) {
        return false;
    }
    $row = $new;

    return true;
}));
bench('send() AtomicCallbackStore', $n, static fn() => $atomic->send('FLIP'));

$locking = new Machine($toggle, new LockingStore(new InMemoryStore('off'), static fn(Closure $work): mixed => $work()));
bench('send() LockingStore', $n, static fn() => $locking->send('FLIP'));

$ref = new stdClass();
$chainDef = Definition::builder()
    ->initial('x')
    ->states('x', 'y')
    ->onEnter('y', static function () use ($ref): void {
        $ref->machine->send('BACK');
    })
    ->transition(Transition::on('GO', 'x', 'y'), Transition::on('BACK', 'y', 'x'))
    ->build();
$ref->machine = new Machine($chainDef);
bench('send() + 1 event chained from onEnter', $n, static fn() => $ref->machine->send('GO'));

$history = new Machine($toggle, recordHistory: 100);
bench('send() with history: 100', $n, static fn() => $history->send('FLIP'));

bench('stream() 10 events', (int) ($n / 10), static function () use ($toggle): void {
    foreach (new Machine($toggle)->stream(array_fill(0, 10, 'FLIP')) as $result) {
    }
});

bench('Definition with ' . count($wide) . ' transitions', (int) ($n / 100), static fn() => new Definition('s0', $states, $wide));
$wideDefinition = $wideMachine->definition;
bench('unreachableStates() on 50 states', (int) ($n / 20), static fn() => $wideDefinition->unreachableStates());

bench('Fiber: send() inside a fiber', (int) ($n / 10), static function () use ($plain): void {
    $fiber = new Fiber(static fn() => $plain->send('FLIP'));
    $fiber->start();
});

printf("\npeak memory: %s KB\n", number_format(memory_get_peak_usage() / 1024));
