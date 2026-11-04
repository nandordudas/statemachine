<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Closure;
use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\AfterHookFailedException;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Store\LockingStore;
use Dudas\StateMachine\Tests\Fixtures\MachineRef;
use Dudas\StateMachine\Transition;
use Fiber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Long-running workers send millions of events through one machine: nothing a send leaves behind may
 * accumulate, whichever path it takes (success, refusal, failure, chaining, fibers).
 */
final class MemoryTest extends TestCase
{
    private const int ITERATIONS = 5_000;

    /** Leaking 13 bytes or more per operation exceeds this. */
    private const int TOLERANCE = 64 * 1024;

    /**
     * @param Closure(): mixed $operation
     */
    private static function assertNoGrowth(Closure $operation): void
    {
        for ($i = 0; $i < 200; $i++) {
            $operation();
        }
        gc_collect_cycles();
        $before = memory_get_usage();

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $operation();
        }
        gc_collect_cycles();
        $growth = memory_get_usage() - $before;

        self::assertLessThan(self::TOLERANCE, $growth, sprintf(
            'Memory grew by %d bytes over %d operations (%.1f B/op).',
            $growth,
            self::ITERATIONS,
            $growth / self::ITERATIONS,
        ));
    }

    private static function toggle(): Definition
    {
        return Definition::fromTable('off', [['FLIP', 'off', 'on'], ['FLIP', 'on', 'off']]);
    }

    /**
     * @return iterable<string, array{Closure(): (Closure(): mixed)}>
     */
    public static function scenarios(): iterable
    {
        yield 'send' => [static function (): Closure {
            $machine = new Machine(self::toggle());

            return static fn() => $machine->send('FLIP');
        }];

        yield 'send with guards and hooks' => [static function (): Closure {
            $machine = new Machine(new Definition('off', ['off', 'on'], [
                Transition::on('FLIP', 'off', 'on')
                    ->withGuard(static fn(): bool => true)
                    ->withBefore(static fn() => null)
                    ->withAfter(static fn() => null),
                Transition::on('FLIP', 'on', 'off'),
            ]));

            return static fn() => $machine->send('FLIP');
        }];

        yield 'refusals through sendIfAllowed and tryTransition' => [static function (): Closure {
            $machine = new Machine(self::toggle());

            return static function () use ($machine): void {
                $machine->sendIfAllowed('NOPE');
                $machine->tryTransition('NOPE');
            };
        }];

        yield 'refusal thrown by send' => [static function (): Closure {
            $machine = new Machine(Definition::fromTable('a', [['GO', 'a', 'b']]), new InMemoryStore('b'));

            return static function () use ($machine): void {
                try {
                    $machine->send('GO');
                } catch (TransitionNotAllowedException) {
                }
            };
        }];

        yield 'failing after hook' => [static function (): Closure {
            $machine = new Machine(new Definition('off', ['off', 'on'], [
                Transition::on('FLIP', 'off', 'on')->withAfter(static fn() => throw new \RuntimeException('late')),
                Transition::on('FLIP', 'on', 'off'),
            ]));

            return static function () use ($machine): void {
                try {
                    $machine->send('FLIP');
                } catch (AfterHookFailedException) {
                }
            };
        }];

        yield 'failing before hook with abort hook' => [static function (): Closure {
            $machine = new Machine(new Definition('a', ['a', 'b'], [
                Transition::on('GO', 'a', 'b')
                    ->withBefore(static fn() => throw new \RuntimeException('no'))
                    ->withAbort(static fn() => null),
            ]));

            return static function () use ($machine): void {
                try {
                    $machine->send('GO');
                } catch (\RuntimeException) {
                }
            };
        }];

        yield 'guard throwing inside can' => [static function (): Closure {
            $machine = new Machine(new Definition('a', ['a', 'b'], [
                Transition::on('GO', 'a', 'b')->withGuard(static fn(): bool => throw new \RuntimeException('guard')),
            ]));

            return static function () use ($machine): void {
                try {
                    $machine->can('GO');
                } catch (\RuntimeException) {
                }
            };
        }];

        yield 'events chained from hooks' => [static function (): Closure {
            $ref = new MachineRef();
            $ref->machine = new Machine(Definition::builder()
                ->initial('x')
                ->states('x', 'y')
                ->onEnter('y', static function () use ($ref): void {
                    $ref->get()->send('BACK');
                })
                ->transition(Transition::on('GO', 'x', 'y'), Transition::on('BACK', 'y', 'x'))
                ->build());

            return static fn() => $ref->get()->send('GO');
        }];

        yield 'bounded history' => [static function (): Closure {
            $machine = new Machine(self::toggle(), recordHistory: 10);

            return static fn() => $machine->send('FLIP');
        }];

        yield 'atomic store with a lagging reader' => [static function (): Closure {
            $row = new \ArrayObject(['status' => 'off']);
            $machine = new Machine(self::toggle(), new AtomicCallbackStore(
                static fn(): string => 'off', // never catches up
                static function (string $expected, string $new) use ($row): bool {
                    $row['status'] = $new;

                    return true;
                },
            ));

            return static function () use ($machine): void {
                $machine->sendIfAllowed('FLIP');
            };
        }];

        yield 'locking store' => [static function (): Closure {
            $machine = new Machine(self::toggle(), new LockingStore(
                new InMemoryStore('off'),
                static fn(Closure $work): mixed => $work(),
            ));

            return static fn() => $machine->send('FLIP');
        }];

        yield 'stream' => [static function (): Closure {
            $machine = new Machine(self::toggle());

            return static function () use ($machine): void {
                foreach ($machine->stream(['FLIP', 'NOPE', 'FLIP']) as $result) {
                }
            };
        }];

        yield 'send from fibers, including a rejected concurrent one' => [static function (): Closure {
            $machine = new Machine(new Definition('off', ['off', 'on'], [
                Transition::on('FLIP', 'off', 'on')->withBefore(static fn() => Fiber::suspend()),
                Transition::on('FLIP', 'on', 'off'),
            ]));

            return static function () use ($machine): void {
                $fiber = new Fiber(static fn() => $machine->send('FLIP'));
                $fiber->start(); // suspended in the before hook

                try {
                    $machine->send('FLIP');
                } catch (ConcurrentTransitionException) {
                }

                $fiber->resume();
                $machine->send('FLIP'); // back to off
            };
        }];
    }

    /**
     * @param Closure(): (Closure(): mixed) $scenario builds the machine and returns the operation to repeat
     */
    #[DataProvider('scenarios')]
    public function testRepeatedOperationsDoNotAccumulateMemory(Closure $scenario): void
    {
        self::assertNoGrowth($scenario());
    }

    public function testAMachineIsFreedWithoutWaitingForTheCycleCollector(): void
    {
        $definition = self::toggle();
        $machine = new Machine($definition, recordHistory: true);
        $machine->sendAll(['FLIP', 'FLIP', 'FLIP']);
        $probe = \WeakReference::create($machine);

        unset($machine);

        self::assertNull($probe->get(), 'a machine holds no reference cycle to itself');
    }
}
