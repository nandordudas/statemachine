<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\AfterHookFailedException;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\StateMachineException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use Dudas\StateMachine\Exception\UnknownEventException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\Store\CallbackStore;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Tests\Fixtures\VideoEvent;
use Dudas\StateMachine\Tests\Fixtures\VideoState;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;

final class MachineTest extends VideoTestCase
{
    public function testStartsInTheInitialState(): void
    {
        self::assertSame('stopped', new Machine($this->video())->state);
    }

    public function testFollowsTransitions(): void
    {
        $machine = new Machine($this->video(extra: [Transition::on('RESUME', 'paused', 'playing')]));

        $machine->send('PLAY');
        self::assertTrue($machine->is('playing'));

        $machine->send('PAUSE')->send('RESUME');
        self::assertSame('playing', $machine->state);
    }

    public function testAcceptsEnumsForEventsAndStates(): void
    {
        $machine = new Machine($this->video());

        $machine->send(VideoEvent::Play);

        self::assertTrue($machine->is(VideoState::Playing));
        self::assertTrue($machine->is('stopped', VideoState::Playing));
        self::assertFalse($machine->is(VideoState::Paused));
        self::assertTrue($machine->can(VideoEvent::Stop));
    }

    public function testListsAvailableEvents(): void
    {
        $machine = new Machine($this->video('playing'));
        self::assertSame(['STOP', 'PAUSE'], $machine->availableEvents());

        $machine->send('STOP');
        self::assertSame(['PLAY'], $machine->availableEvents());
    }

    public function testUnknownEventThrows(): void
    {
        $this->expectException(UnknownEventException::class);

        new Machine($this->video())->send('TURN_OFF');
    }

    public function testEventNotValidInTheCurrentStateThrowsAndKeepsState(): void
    {
        $machine = new Machine($this->video());

        try {
            $machine->send('STOP');
            self::fail('Expected an exception.');
        } catch (TransitionNotAllowedException $e) {
            self::assertSame(TransitionNotAllowedException::class, $e::class);
            self::assertSame('STOP', $e->event);
            self::assertSame('stopped', $e->from);
        }

        self::assertSame('stopped', $machine->state);
    }

    public function testCanIsFalseForUnknownEventsInsteadOfThrowing(): void
    {
        $machine = new Machine($this->video());

        self::assertFalse($machine->can('NOPE'));
        self::assertFalse($machine->can('STOP'));
        self::assertTrue($machine->can('PLAY'));
    }

    public function testGuardRejectionIsDistinguishedAndKeepsState(): void
    {
        $definition = $this->video(extra: [
            Transition::on('TURN_ON', 'stopped', 'playing')->withGuard(static fn(TransitionContext $c): bool => (bool) ($c->payload['powered'] ?? false)),
        ]);
        $machine = new Machine($definition);

        self::assertFalse($machine->can('TURN_ON'));
        self::assertTrue($machine->can('TURN_ON', ['powered' => true]));

        try {
            $machine->send('TURN_ON');
            self::fail('Expected an exception.');
        } catch (GuardRejectedException $e) {
            self::assertInstanceOf(TransitionNotAllowedException::class, $e);
            self::assertInstanceOf(StateMachineException::class, $e);
        }
        self::assertSame('stopped', $machine->state);

        $machine->send('TURN_ON', ['powered' => true]);
        self::assertSame('playing', $machine->state);
    }

    public function testFirstPermittedTransitionWinsForConditionalBranching(): void
    {
        $definition = Definition::builder()
            ->initial('draft')
            ->states('draft', 'review', 'published')
            ->transition(
                Transition::on('SUBMIT', 'draft', 'published')->withGuard(static fn(TransitionContext $c): bool => (bool) ($c->payload['trusted'] ?? false)),
                Transition::on('SUBMIT', 'draft', 'review'),
            )
            ->build();

        self::assertSame('review', new Machine($definition)->send('SUBMIT')->state);
        self::assertSame('published', new Machine($definition)->send('SUBMIT', ['trusted' => true])->state);
    }

    public function testHooksRunInOrderAroundTheStateChange(): void
    {
        $log = [];
        /** @var \stdClass&object{machine: Machine} $holder */
        $holder = new \stdClass();
        $transition = Transition::on('PLAY', 'stopped', 'playing')
            ->withBefore(static function (TransitionContext $c) use (&$log, $holder): void {
                $log[] = "before:{$c->from}->{$c->to}:" . $holder->machine->state;
            })
            ->withAfter(static function (TransitionContext $c) use (&$log, $holder): void {
                $log[] = "after:{$c->event}:" . $holder->machine->state;
            });
        $holder->machine = $machine = new Machine(Definition::builder()->initial('stopped')->states('stopped', 'playing')->transition($transition)->build());

        $machine->send('PLAY');

        self::assertSame(['before:stopped->playing:stopped', 'after:PLAY:playing'], $log);
    }

    public function testFailingBeforeHookAbortsTheTransition(): void
    {
        $transition = Transition::on('PLAY', 'stopped', 'playing')
            ->withBefore(static fn() => throw new \RuntimeException('nope'));
        $machine = new Machine(Definition::builder()->initial('stopped')->states('stopped', 'playing')->transition($transition)->build());

        try {
            $machine->send('PLAY');
            self::fail('Expected an exception.');
        } catch (\RuntimeException) {
        }

        self::assertSame('stopped', $machine->state);
    }

    public function testSeveralMachinesShareOneDefinitionIndependently(): void
    {
        $definition = $this->video();
        $a = new Machine($definition);
        $b = new Machine($definition);

        $a->send('PLAY');

        self::assertSame('playing', $a->state);
        self::assertSame('stopped', $b->state);
    }

    public function testDrivesStateOwnedByAnotherObject(): void
    {
        $order = new class {
            public string $status = 'stopped';
        };
        $machine = new Machine(
            $this->video(),
            new CallbackStore(static fn(): string => $order->status, static function (string $s) use ($order): void {
                $order->status = $s;
            }),
        );

        $machine->send('PLAY');

        self::assertSame('playing', $order->status);
    }

    public function testStoreStateMustBeKnownToTheDefinition(): void
    {
        $this->expectException(UnknownStateException::class);

        new Machine($this->video(), new InMemoryStore('ghost'));
    }

    public function testNestedSendFromBeforeHookDoesNotOverwriteState(): void
    {
        $machine = null;
        $definition = $this->video(extra: [
            Transition::on('FORCE_PAUSE', 'stopped', 'paused'),
            Transition::on('GO', 'stopped', 'playing')
                ->withBefore(static function () use (&$machine): void {
                    $machine->send('FORCE_PAUSE');
                }),
        ]);
        $machine = new Machine($definition);

        try {
            $machine->send('GO');
            self::fail('Expected the stale transition to be rejected.');
        } catch (ConcurrentTransitionException) {
        }

        self::assertSame('paused', $machine->state);
    }

    public function testAfterHookFailureDoesNotRollBackState(): void
    {
        $transition = Transition::on('PLAY', 'stopped', 'playing')
            ->withAfter(static fn() => throw new \RuntimeException('late'));
        $machine = new Machine(Definition::builder()->initial('stopped')->states('stopped', 'playing')->transition($transition)->build());

        try {
            $machine->send('PLAY');
            self::fail('Expected an exception.');
        } catch (AfterHookFailedException $e) {
            self::assertSame('late', $e->getPrevious()?->getMessage());
            self::assertSame('PLAY', $e->context->event);
        }

        self::assertSame('playing', $machine->state);
    }

    public function testBranchingFallsThroughToGuardRejectionWhenAllBranchesVeto(): void
    {
        $veto = static fn(): bool => false;
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b', 'c')
            ->transition(
                Transition::on('GO', 'a', 'b')->withGuard($veto),
                Transition::on('GO', 'a', 'c')->withGuard($veto),
            )
            ->build();

        $this->expectException(GuardRejectedException::class);

        new Machine($definition)->send('GO');
    }

    public function testStateHooksRunAroundTheTransition(): void
    {
        $log = [];
        $definition = Definition::builder()
            ->initial('stopped')
            ->states('stopped', 'playing')
            ->onExit('stopped', static function () use (&$log): void {
                $log[] = 'exit:stopped';
            })
            ->onEnter('playing', static function () use (&$log): void {
                $log[] = 'enter:playing';
            })
            ->transition(
                Transition::on('PLAY', 'stopped', 'playing')
                    ->withBefore(static function () use (&$log): void {
                        $log[] = 'before';
                    })
                    ->withAfter(static function () use (&$log): void {
                        $log[] = 'after';
                    }),
            )
            ->build();

        new Machine($definition)->send('PLAY');

        self::assertSame(['before', 'exit:stopped', 'enter:playing', 'after'], $log);
    }

    public function testFailingExitHookAbortsTheTransition(): void
    {
        $definition = Definition::builder()
            ->initial('stopped')
            ->states('stopped', 'playing')
            ->onExit('stopped', static fn() => throw new \RuntimeException('stay'))
            ->transition(Transition::on('PLAY', 'stopped', 'playing'))
            ->build();
        $machine = new Machine($definition);

        try {
            $machine->send('PLAY');
            self::fail('Expected an exception.');
        } catch (\RuntimeException) {
        }

        self::assertSame('stopped', $machine->state);
    }

    public function testStateHooksMustReferToDeclaredStates(): void
    {
        $this->expectException(\Dudas\StateMachine\Exception\InvalidDefinitionException::class);

        Definition::builder()->initial('a')->states('a')->onEnter('ghost', static fn() => null)->build();
    }

    public function testAtomicStoreRefusesAWriteThatRacesWithAnotherWriter(): void
    {
        $status = 'stopped';
        $store = new AtomicCallbackStore(
            static function () use (&$status): string {
                return $status;
            },
            static function (string $expected, string $new) use (&$status): bool {
                if ($status !== $expected) {
                    return false;
                }
                $status = $new;

                return true;
            },
        );
        $machine = new Machine(
            $this->video(extra: [
                Transition::on('GO', 'stopped', 'playing')->withBefore(static function () use (&$status): void {
                    $status = 'paused'; // another writer, bypassing the machine
                }),
            ]),
            $store,
        );

        $this->expectException(ConcurrentTransitionException::class);

        try {
            $machine->send('GO');
        } finally {
            self::assertSame('paused', $status);
        }
    }

    public function testStoreWithoutStateIsRejected(): void
    {
        $this->expectException(UnknownStateException::class);

        new Machine($this->video(), new CallbackStore(static fn(): ?string => null, static function (): void {}));
    }

    public function testRejectsIntBackedEnums(): void
    {
        $this->expectException(\Dudas\StateMachine\Exception\InvalidDefinitionException::class);

        new Machine($this->video())->send(IntEvent::One);
    }
}

enum IntEvent: int
{
    case One = 1;
}
