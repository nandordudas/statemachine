<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Closure;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\AfterHookFailedException;
use Dudas\StateMachine\Exception\ChainLimitExceededException;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\TransitionApplied;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Store\LockingStore;
use Dudas\StateMachine\Store\PropertyStore;
use Dudas\StateMachine\Tests\Fixtures\VideoState as S;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function Dudas\StateMachine\machine;

final class HardeningTest extends TestCase
{
    public function testAbortHooksOfAnEarlierSendAreNotRunAgainWhenALaterSendFailsBeforeItStarts(): void
    {
        $aborts = 0;
        $calls = 0;
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(
                Transition::on('BOOM', 'a', 'b')->withBefore(static function (): void {
                    throw new RuntimeException('before failed');
                })->withAbort(static function () use (&$aborts): void {
                    $aborts++;
                }),
                Transition::on('GO', 'a', 'b'),
            )
            ->build();
        $store = new LockingStore(new InMemoryStore('a'), static function (Closure $work) use (&$calls): mixed {
            if (++$calls === 2) {
                throw new RuntimeException('BEGIN failed');
            }

            return $work();
        });
        $machine = new Machine($definition, $store);

        try {
            $machine->send('BOOM');
            self::fail('Expected the before hook to fail.');
        } catch (RuntimeException $e) {
            self::assertSame('before failed', $e->getMessage());
        }
        self::assertSame(1, $aborts);

        try {
            $machine->send('GO');
            self::fail('Expected the transaction to fail.');
        } catch (RuntimeException $e) {
            self::assertSame('BEGIN failed', $e->getMessage());
        }

        self::assertSame(1, $aborts, 'a transition that never started must not be compensated');
    }

    public function testRefusalIsExplainedInsideTheTransactionNotAfterIt(): void
    {
        $log = [];
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(Transition::on('GO', 'a', 'b')->withGuard(static function () use (&$log): bool {
                $log[] = 'guard';

                return false;
            }))
            ->build();
        $store = new LockingStore(new InMemoryStore('a'), static function (Closure $work) use (&$log): mixed {
            $log[] = 'begin';

            try {
                return $work();
            } finally {
                $log[] = 'end';
            }
        });

        try {
            new Machine($definition, $store)->send('GO');
            self::fail('Expected a guard rejection.');
        } catch (GuardRejectedException) {
        }

        self::assertSame('end', end($log), 'no guard may run after the transaction has ended');
        self::assertSame('begin', $log[0]);
    }

    public function testQuietRefusalDoesNotPayForAnExplanation(): void
    {
        $calls = 0;
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(Transition::on('GO', 'a', 'b')->withGuard(static function () use (&$calls): bool {
                $calls++;

                return false;
            }))
            ->build();
        $machine = new Machine($definition);

        self::assertFalse($machine->sendIfAllowed('GO'));
        self::assertSame(1, $calls);

        self::assertInstanceOf(GuardRejectedException::class, $machine->tryTransition('GO')->failure);
        self::assertSame(3, $calls, 'a reported refusal asks the guards once more for the blockers');
    }

    public function testSelfLoopRunsHooksButWritesNothing(): void
    {
        $store = new class implements StateStore {
            public int $writes = 0;

            public function get(): string
            {
                return 'a';
            }

            public function set(string $state): void
            {
                $this->writes++;
            }
        };
        $after = 0;
        $definition = Definition::builder()
            ->initial('a')
            ->states('a')
            ->transition(Transition::on('PING', 'a', 'a')->withAfter(static function () use (&$after): void {
                $after++;
            }))
            ->build();

        $machine = new Machine($definition, $store);
        $machine->send('PING');

        self::assertSame(0, $store->writes);
        self::assertSame(1, $after);
        self::assertSame('a', $machine->state);
    }

    public function testExceptionsTellWhetherToRetryAndWhetherTheStateChanged(): void
    {
        self::assertTrue(ConcurrentTransitionException::lostRace('a', 'b')->retryable);
        self::assertTrue(ConcurrentTransitionException::forEvent('GO', 'a', 'b')->retryable);
        self::assertTrue(ConcurrentTransitionException::busy('GO')->retryable);
        self::assertFalse(ConcurrentTransitionException::nestedSend('GO')->retryable);
        self::assertFalse(ConcurrentTransitionException::sendFromGuard('GO')->retryable);
        self::assertInstanceOf(LogicException::class, ConcurrentTransitionException::lostRace('a', 'b'));

        self::assertContains(TransitionApplied::class, class_implements(ChainLimitExceededException::class));

        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(Transition::on('GO', 'a', 'b')->withAfter(static function (): void {
                throw new RuntimeException('late');
            }))
            ->build();

        try {
            new Machine($definition)->send('GO');
            self::fail('Expected the after hook to fail.');
        } catch (TransitionApplied $e) {
            self::assertInstanceOf(AfterHookFailedException::class, $e);
        }
    }

    public function testAtomicStoreForgetsItsWriteWhenTheNextWriteLosesTheRace(): void
    {
        $entity = 'a'; // what the reader sees: nobody refreshes it
        $row = 'a'; // the real row
        $store = new AtomicCallbackStore(static function () use (&$entity): string {
            return $entity;
        }, static function (string $expected, string $new) use (&$row): bool {
            if ($row !== $expected) {
                return false;
            }
            $row = $new;

            return true;
        });

        self::assertTrue($store->compareAndSet('a', 'b'));
        self::assertSame('b', $store->get(), 'the reader lags: the store reports its own write');

        $row = 'a'; // another writer moves the row back

        self::assertFalse($store->compareAndSet('b', 'c'));
        self::assertSame('a', $store->get(), 'after a lost race the store trusts the reader again');
    }

    public function testPropertyStoreReportsAnUninitializedStateProperty(): void
    {
        $entity = new class {
            public string $status;
        };

        $this->expectException(UnknownStateException::class);

        new PropertyStore($entity)->get();
    }

    public function testMachineHelperRejectsAStatesClassThatIsNotAnEnum(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        /** @phpstan-ignore argument.type */
        machine('a', \stdClass::class, []);
    }

    public function testContextReadsStatesAsEnumCases(): void
    {
        $context = new TransitionContext('PLAY', 'stopped', 'playing');

        self::assertSame(S::Stopped, $context->fromAs(S::class));
        self::assertSame(S::Playing, $context->toAs(S::class));

        $this->expectException(UnknownStateException::class);
        new TransitionContext('GO', 'ghost', 'playing')->fromAs(S::class);
    }
}
