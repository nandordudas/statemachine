<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\Store\CallbackStore;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Store\LockingStore;
use Dudas\StateMachine\Store\PropertyStore;
use Dudas\StateMachine\Tests\Fixtures\VideoEvent as E;
use Dudas\StateMachine\Tests\Fixtures\VideoState as S;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use Fiber;
use PHPUnit\Framework\TestCase;

use function Dudas\StateMachine\machineFor;

final class ConcurrencyTest extends TestCase
{
    private function abc(Transition ...$extra): Definition
    {
        return Definition::builder()
            ->initial('a')
            ->states('a', 'b', 'c')
            ->transition(Transition::on('OTHER', 'a', 'c'), ...$extra)
            ->build();
    }

    public function testAnotherFiberCannotSendWhileABeforeHookIsSuspended(): void
    {
        $machine = new Machine($this->abc(Transition::on('GO', 'a', 'b')->withBefore(static fn() => Fiber::suspend())));
        $fiber = new Fiber(static fn() => $machine->send('GO'));
        $fiber->start();

        try {
            $machine->send('OTHER');
            self::fail('Expected the concurrent send to be rejected.');
        } catch (ConcurrentTransitionException $e) {
            self::assertStringContainsString('another caller', $e->getMessage());
        }

        self::assertTrue($machine->can('OTHER'), 'reading stays possible');

        $fiber->resume();

        self::assertTrue($fiber->isTerminated());
        self::assertSame('b', $machine->state);
    }

    public function testAnotherFiberCannotSendWhileAnAfterHookIsSuspended(): void
    {
        $machine = new Machine(Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(
                Transition::on('GO', 'a', 'b')->withAfter(static fn() => Fiber::suspend()),
                Transition::on('BACK', 'b', 'a'),
            )
            ->build());
        $fiber = new Fiber(static fn() => $machine->send('GO'));
        $fiber->start();

        $this->expectException(ConcurrentTransitionException::class);

        try {
            $machine->send('BACK');
        } finally {
            $fiber->resume();
            self::assertSame('b', $machine->state);
        }
    }

    public function testAbortHooksCompensateWhenABeforeOrExitHookFails(): void
    {
        $aborted = [];
        $compensate = static function (TransitionContext $c) use (&$aborted): void {
            $aborted[] = $c->event;
        };
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->onExit('a', static fn() => throw new \RuntimeException('exit failed'))
            ->transition(
                Transition::on('GO', 'a', 'b')->withAbort($compensate),
                Transition::on('VETOED', 'a', 'b')->withGuard(static fn(): bool => false)->withAbort($compensate),
            )
            ->build();
        $machine = new Machine($definition);

        try {
            $machine->send('GO');
            self::fail('Expected the exit hook failure.');
        } catch (\RuntimeException $e) {
            self::assertSame('exit failed', $e->getMessage(), 'the original failure propagates');
        }

        try {
            $machine->send('VETOED');
            self::fail('Expected a guard rejection.');
        } catch (GuardRejectedException) {
        }

        self::assertSame(['GO'], $aborted, 'a refusal never started the transition, so nothing to compensate');
        self::assertSame('a', $machine->state);
    }

    public function testInterleavedWorkersOnOneRowLetOnlyOneWin(): void
    {
        $row = ['status' => 'a'];
        $store = static function () use (&$row): AtomicCallbackStore {
            return new AtomicCallbackStore(static function () use (&$row): string {
                return $row['status'];
            }, static function (string $expected, string $new) use (&$row): bool {
                if ($row['status'] !== $expected) {
                    return false;
                }
                $row['status'] = $new;

                return true;
            });
        };
        $aborted = false;
        $workerA = new Machine($this->abc(Transition::on('GO', 'a', 'b')), $store());
        // B has planned GO and is in its before hook when A applies OTHER on the same row.
        $workerB = new Machine(
            $this->abc(
                Transition::on('GO', 'a', 'b')->withBefore(static function () use ($workerA): void {
                    $workerA->send('OTHER');
                })->withAbort(static function () use (&$aborted): void {
                    $aborted = true;
                }),
            ),
            $store(),
        );

        try {
            $workerB->send('GO');
            self::fail('Expected B to lose the race.');
        } catch (ConcurrentTransitionException) {
        }

        self::assertSame('c', $row['status']);
        self::assertTrue($aborted);
    }

    public function testAtomicStoreWithAReaderThatLagsBehindDoesNotGetStuck(): void
    {
        $entity = (object) ['status' => 'a']; // never updated: only the "row" is
        $row = 'a';
        $store = new AtomicCallbackStore(static fn(): string => (string) $entity->status, static function (
            string $expected,
            string $new,
        ) use (&$row): bool {
            if ($row !== $expected) {
                return false;
            }
            $row = $new;

            return true;
        });
        $machine = new Machine(Definition::fromTable('a', [['GO', 'a', 'b'], ['NEXT', 'b', 'c']]), $store);

        $machine->send('GO');
        self::assertSame('b', $machine->state);

        $result = $machine->tryTransition('NEXT');
        self::assertTrue($result->ok());
        self::assertSame('c', $row);
    }

    public function testAtomicStoreAfterWriteKeepsTheEntityInStep(): void
    {
        $entity = (object) ['status' => 'a'];
        $row = 'a';
        $store = new AtomicCallbackStore(
            static fn(): string => (string) $entity->status,
            static function (string $expected, string $new) use (&$row): bool {
                if ($row !== $expected) {
                    return false;
                }
                $row = $new;

                return true;
            },
            afterWrite: static function (string $new) use ($entity): void {
                $entity->status = $new;
            },
        );

        new Machine(Definition::fromTable('a', [['GO', 'a', 'b']]), $store)->send('GO');

        self::assertSame('b', $entity->status);
        self::assertSame('b', $row);
    }

    public function testLockingStoreDecidesAndWritesInsideTheTransactionAndRunsLateHooksAfterIt(): void
    {
        $log = [];
        $store = new LockingStore(new InMemoryStore('a'), static function (\Closure $work) use (&$log): mixed {
            $log[] = 'begin';
            $result = $work();
            $log[] = 'commit';

            return $result;
        });
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(
                Transition::on('GO', 'a', 'b')
                    ->withGuard(static function () use (&$log): bool {
                        $log[] = 'guard';

                        return true;
                    })
                    ->withBefore(static function () use (&$log): void {
                        $log[] = 'before';
                    })
                    ->withAfter(static function () use (&$log): void {
                        $log[] = 'after';
                    }),
            )
            ->build();

        new Machine($definition, $store)->send('GO');

        self::assertSame(['begin', 'guard', 'before', 'commit', 'after'], $log);
        self::assertSame('b', $store->get());
    }

    public function testLockingStoreRollsBackAndAbortsWhenTheWorkFails(): void
    {
        $rolledBack = false;
        $aborted = false;
        $inner = new InMemoryStore('a');
        $store = new LockingStore($inner, static function (\Closure $work) use (&$rolledBack): mixed {
            try {
                return $work();
            } catch (\Throwable $e) {
                $rolledBack = true;

                throw $e;
            }
        });
        $definition = new Definition('a', ['a', 'b'], [
            Transition::on('GO', 'a', 'b')
                ->withBefore(static fn() => throw new \RuntimeException('no'))
                ->withAbort(static function () use (&$aborted): void {
                    $aborted = true;
                }),
        ]);

        try {
            new Machine($definition, $store)->send('GO');
            self::fail('Expected the before hook failure.');
        } catch (\RuntimeException) {
        }

        self::assertTrue($rolledBack);
        self::assertTrue($aborted);
        self::assertSame('a', $inner->get());
    }

    public function testLockingStoreRequiresTheCallbackToRunTheWork(): void
    {
        $store = new LockingStore(new InMemoryStore('a'), static fn(\Closure $work): mixed => null);

        $this->expectException(InvalidDefinitionException::class);

        new Machine(Definition::fromTable('a', [['GO', 'a', 'b']]), $store)->send('GO');
    }

    public function testLockingStoreComparesAPlainInnerStore(): void
    {
        $status = 'a';
        $store = new LockingStore(
            new CallbackStore(static fn(): string => $status, static function (
                string $s,
            ) use (&$status): void {
                $status = $s;
            }),
            static fn(\Closure $work): mixed => $work(),
        );

        self::assertFalse($store->compareAndSet('b', 'c'));
        self::assertTrue($store->compareAndSet('a', 'b'));
        self::assertSame('b', $status);
    }

    public function testMachineForWithCompareAndSetLetsOnlyOneLoadedCopyWin(): void
    {
        $row = 'stopped';
        $compareAndSet = static function (string $expected, string $new) use (&$row): bool {
            if ($row !== $expected) {
                return false;
            }
            $row = $new;

            return true;
        };
        $definition = Definition::fromTable(S::Stopped, [[E::Play, S::Stopped, S::Playing], [
            E::Pause,
            S::Stopped,
            S::Paused,
        ]]);
        $copyA = new class {
            public S $status = S::Stopped;
        };
        $copyB = new class {
            public S $status = S::Stopped;
        };

        machineFor($definition, $copyA, compareAndSet: $compareAndSet)->send(E::Play);

        try {
            machineFor($definition, $copyB, compareAndSet: $compareAndSet)->send(E::Pause);
            self::fail('Expected the stale copy to lose.');
        } catch (ConcurrentTransitionException) {
        }

        self::assertSame(S::Playing, $copyA->status);
        self::assertSame(S::Stopped, $copyB->status);
        self::assertSame('playing', $row);
    }

    public function testPropertyStoreRejectsStatesItsEnumCannotHoldUpFront(): void
    {
        $entity = new class {
            public S $status = S::Stopped;
        };

        $this->expectException(InvalidDefinitionException::class);

        machineFor(Definition::fromTable(S::Stopped, [[E::Play, S::Stopped, 'rewinding']]), $entity);
    }

    public function testPropertyStoreRejectsReadonlyProperties(): void
    {
        $entity = new readonly class {
            public function __construct(
                public string $status = 'a',
            ) {}
        };

        $this->expectException(InvalidDefinitionException::class);

        new PropertyStore($entity);
    }

    public function testPropertyStoreRefusesToWriteAStateItsEnumCannotHold(): void
    {
        $entity = new class {
            public S $status = S::Stopped;
        };

        $this->expectException(UnknownStateException::class);

        new PropertyStore($entity)->set('rewinding');
    }
}
