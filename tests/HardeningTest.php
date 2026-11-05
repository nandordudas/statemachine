<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\AfterHookFailedException;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\TransitionApplied;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Transition;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class HardeningTest extends TestCase
{
    public function testSelfLoopRunsHooksButWritesNothing(): void
    {
        $store = new class() implements StateStore {
            /** @var int */
            public $writes = 0;

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
        self::assertSame('a', $machine->state());
    }

    public function testExceptionsTellWhetherToRetryAndWhetherTheStateChanged(): void
    {
        self::assertTrue(ConcurrentTransitionException::lostRace('a', 'b')->retryable);
        self::assertTrue(ConcurrentTransitionException::forEvent('GO', 'a', 'b')->retryable);
        self::assertFalse(ConcurrentTransitionException::nestedSend('GO')->retryable);
        self::assertInstanceOf(LogicException::class, ConcurrentTransitionException::lostRace('a', 'b'));

        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(Transition::on('GO', 'a', 'b')->withAfter(static function (): void {
                throw new RuntimeException('late');
            }))
            ->build();

        try {
            (new Machine($definition))->send('GO');
            self::fail('Expected the after hook to fail.');
        } catch (TransitionApplied $e) {
            self::assertInstanceOf(AfterHookFailedException::class, $e);
        }
    }

    public function testPropertyStoreReportsAnUninitializedTypedProperty(): void
    {
        if (PHP_VERSION_ID < 70400) {
            self::markTestSkipped('Typed properties need PHP 7.4.');
        }

        $entity = eval('return new class { public string $status; };');
        self::assertIsObject($entity);

        $this->expectException(\Dudas\StateMachine\Exception\UnknownStateException::class);

        (new \Dudas\StateMachine\Store\PropertyStore($entity))->get();
    }
}
