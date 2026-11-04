<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\AfterHookFailedException;
use Dudas\StateMachine\Exception\ChainLimitExceededException;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Tests\Fixtures\MachineRef;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use Dudas\StateMachine\TransitionResult;
use PHPUnit\Framework\TestCase;

final class RunToCompletionTest extends TestCase
{
    public function testEventsChainedFromHooksRunAfterEveryHookOfTheCurrentTransition(): void
    {
        $ref = new MachineRef();
        /** @var list<string> $log */
        $log = [];
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b', 'c')
            ->onEnter(
                'b',
                static function () use ($ref, &$log): void {
                    $ref->get()->send('NEXT');
                    $log[] = 'enter:b#1 sees ' . $ref->get()->state;
                },
                static function () use ($ref, &$log): void {
                    $log[] = 'enter:b#2 sees ' . $ref->get()->state;
                },
            )
            ->onEnter('c', static function () use (&$log): void {
                $log[] = 'enter:c';
            })
            ->transition(
                Transition::on('GO', 'a', 'b')->withAfter(static function (TransitionContext $c) use (
                    $ref,
                    &$log,
                ): void {
                    $log[] = "after:GO sees {$ref->get()->state}, context to {$c->to}";
                }),
                Transition::on('NEXT', 'b', 'c'),
            )
            ->build();
        $ref->machine = new Machine($definition);

        $result = $ref->get()->tryTransition('GO');

        self::assertSame(
            [
                'enter:b#1 sees b',
                'enter:b#2 sees b',
                'after:GO sees b, context to b',
                'enter:c',
            ],
            $log,
        );
        self::assertSame('b', $result->state, 'the result describes this transition, not the chained one');
        self::assertSame('GO', $result->context?->event);
        self::assertSame('c', $ref->get()->state);
    }

    public function testATryFromAHookIsQueuedAndARefusalIsDropped(): void
    {
        $ref = new MachineRef();
        $queued = null;
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->onEnter('b', static function () use ($ref, &$queued): void {
                $queued = $ref->get()->tryTransition('GO'); // not allowed from b
            })
            ->transition(Transition::on('GO', 'a', 'b'))
            ->build();
        $ref->machine = new Machine($definition);

        $ref->get()->send('GO');

        self::assertInstanceOf(TransitionResult::class, $queued);
        self::assertTrue($queued->queued);
        self::assertTrue($queued->ok());
        self::assertSame('b', $ref->get()->state);
    }

    public function testAChainedRefusalThrowsFromTheOuterSendAfterItWasApplied(): void
    {
        $ref = new MachineRef();
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->onEnter('b', static function () use ($ref): void {
                $ref->get()->send('GO');
            })
            ->transition(Transition::on('GO', 'a', 'b'))
            ->build();
        $ref->machine = new Machine($definition);

        try {
            $ref->get()->send('GO');
            self::fail('Expected the chained event to be refused.');
        } catch (TransitionNotAllowedException $e) {
            self::assertSame('b', $e->from);
        }

        self::assertSame('b', $ref->get()->state);
    }

    public function testACycleOfHooksStopsAtTheChainLimitInsteadOfOverflowingTheStack(): void
    {
        $ref = new MachineRef();
        $bounce = static function () use ($ref): void {
            $ref->get()->send('FLIP');
        };
        $definition = Definition::builder()
            ->initial('x')
            ->states('x', 'y')
            ->onEnter('x', $bounce)
            ->onEnter('y', $bounce)
            ->transition(Transition::on('FLIP', 'x', 'y'), Transition::on('FLIP', 'y', 'x'))
            ->build();
        $ref->machine = new Machine($definition, recordHistory: true, maxChainedEvents: 10);

        try {
            $ref->get()->send('FLIP');
            self::fail('Expected the chain limit to stop the cycle.');
        } catch (ChainLimitExceededException) {
        }

        self::assertCount(11, $ref->get()->history(), 'the first event and 10 chained ones');

        // A limit of 0 forbids chaining altogether.
        $ref->machine = new Machine($definition, maxChainedEvents: 0);
        $this->expectException(ChainLimitExceededException::class);
        $ref->get()->send('FLIP');
    }

    public function testEveryPostHookRunsAndAllFailuresAreReported(): void
    {
        $ref = new MachineRef();
        $ran = 0;
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b', 'c')
            ->onEnter(
                'b',
                static function () use ($ref): void {
                    $ref->get()->send('NEXT');

                    throw new \RuntimeException('first');
                },
                static function () use (&$ran): void {
                    $ran++;
                },
            )
            ->transition(
                Transition::on('GO', 'a', 'b')->withAfter(static fn() => throw new \RuntimeException('second')),
                Transition::on('NEXT', 'b', 'c'),
            )
            ->build();
        $ref->machine = new Machine($definition);

        try {
            $ref->get()->send('GO');
            self::fail('Expected an AfterHookFailedException.');
        } catch (AfterHookFailedException $e) {
            self::assertSame('first', $e->getPrevious()?->getMessage());
            $messages = array_map(static fn(\Exception $f): string => $f->getMessage(), $e->failures);
            self::assertSame(['first', 'second'], $messages);
        }

        self::assertSame(1, $ran, 'a failing hook does not skip the next one');
        self::assertSame('b', $ref->get()->state, 'events queued by a failed transition are dropped');
    }

    public function testSendingFromAGuardIsRejected(): void
    {
        $ref = new MachineRef();
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(Transition::on('GO', 'a', 'b')->withGuard(static function () use ($ref): bool {
                $ref->get()->send('GO');

                return true;
            }))
            ->build();
        $ref->machine = new Machine($definition);

        try {
            $ref->get()->can('GO');
            self::fail('Expected the send from a guard to be rejected.');
        } catch (ConcurrentTransitionException $e) {
            self::assertStringContainsString('guard', $e->getMessage());
        }

        $this->expectException(ConcurrentTransitionException::class);
        $ref->get()->send('GO');
    }

    public function testGuardsMayStillAskCan(): void
    {
        $ref = new MachineRef();
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b')
            ->transition(
                Transition::on('PEEK', 'a', 'a'),
                Transition::on('GO', 'a', 'b')->withGuard(static fn(): bool => $ref->get()->can('PEEK')),
            )
            ->build();
        $ref->machine = new Machine($definition);

        self::assertSame('b', $ref->get()->send('GO')->state);
    }

    public function testHistoryCanKeepOnlyTheLatestTransitions(): void
    {
        $machine = new Machine(Definition::fromTable('a', [
            ['GO', 'a', 'b'],
            ['GO', 'b', 'c'],
            ['GO', 'c', 'a'],
        ]), recordHistory: 2);

        $machine->sendAll(['GO', 'GO', 'GO']);

        $from = array_map(static fn(TransitionContext $c): string => $c->from, $machine->history());
        self::assertSame(['b', 'c'], $from);
    }

    public function testBoundedHistoryKeepsOrderAcrossManyWrapArounds(): void
    {
        $machine = new Machine(Definition::fromTable('s0', [
            ['GO', 's0', 's1'],
            ['GO', 's1', 's2'],
            ['GO', 's2', 's3'],
            ['GO', 's3', 's4'],
            ['GO', 's4', 's0'],
        ]), recordHistory: 3);

        for ($i = 0; $i < 13; $i++) {
            $machine->send('GO');
        }

        // Transitions 11, 12 and 13 left s0, s1 and s2.
        $from = array_map(static fn(TransitionContext $c): string => $c->from, $machine->history());
        self::assertSame(['s0', 's1', 's2'], $from);
    }

    public function testNegativeLimitsAreRejected(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        new Machine(Definition::fromTable('a', [['GO', 'a', 'b']]), maxChainedEvents: -1);
    }
}
