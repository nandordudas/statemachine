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
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use RuntimeException;

final class MachineTest extends VideoTestCase
{
    public function testFollowsTransitionsAndExposesState(): void
    {
        $machine = new Machine($this->video('stopped', [Transition::on('RESUME', 'paused', 'playing')]));

        self::assertSame('stopped', $machine->state());
        self::assertSame('stopped', $machine->state);

        $machine->send('PLAY')->send('PAUSE')->send('RESUME');

        self::assertTrue($machine->is('playing'));
        self::assertTrue($machine->is('stopped', 'playing'));
        self::assertFalse($machine->is('paused'));
    }

    public function testListsAvailableEventsAndCan(): void
    {
        $machine = new Machine($this->video('playing'));

        self::assertSame(['STOP', 'PAUSE'], $machine->availableEvents());
        self::assertTrue($machine->can('STOP'));
        self::assertFalse($machine->can('PLAY'));
        self::assertFalse($machine->can('NOPE'));
    }

    public function testUnknownEventNotAllowedAndGuardRejectedAreDistinct(): void
    {
        $definition = $this->video('stopped', [
            Transition::on('TURN_ON', 'stopped', 'playing')->withGuard(static function (TransitionContext $c): bool {
                return (bool) ($c->payload['powered'] ?? false);
            }),
        ]);
        $machine = new Machine($definition);

        try {
            $machine->send('NOPE');
            self::fail('Expected an exception.');
        } catch (UnknownEventException $e) {
            self::assertInstanceOf(StateMachineException::class, $e);
        }

        try {
            $machine->send('STOP');
            self::fail('Expected an exception.');
        } catch (TransitionNotAllowedException $e) {
            self::assertSame(TransitionNotAllowedException::class, get_class($e));
            self::assertSame('STOP', $e->event);
            self::assertSame('stopped', $e->from);
        }

        try {
            $machine->send('TURN_ON');
            self::fail('Expected an exception.');
        } catch (GuardRejectedException $e) {
            self::assertInstanceOf(TransitionNotAllowedException::class, $e);
        }
        self::assertSame('stopped', $machine->state());

        $machine->send('TURN_ON', ['powered' => true]);
        self::assertSame('playing', $machine->state());
    }

    public function testFirstPermittedTransitionWinsForConditionalBranching(): void
    {
        $definition = Definition::builder()
            ->initial('draft')
            ->states('draft', 'review', 'published')
            ->transition(
                Transition::on('SUBMIT', 'draft', 'published')->withGuard(static function (TransitionContext $c): bool {
                    return (bool) ($c->payload['trusted'] ?? false);
                }),
                Transition::on('SUBMIT', 'draft', 'review'),
            )
            ->build();

        self::assertSame(
            'review',
            (new Machine($definition))
                ->send('SUBMIT')
                ->state(),
        );
        self::assertSame(
            'published',
            (new Machine($definition))
                ->send('SUBMIT', ['trusted' => true])
                ->state(),
        );
    }

    public function testHookOrderAroundTheStateChange(): void
    {
        $log = [];
        $note = static function (string $what) use (&$log): callable {
            return static function () use (&$log, $what): void {
                $log[] = $what;
            };
        };
        $definition = Definition::builder()
            ->initial('stopped')
            ->states('stopped', 'playing')
            ->onExit('stopped', $note('exit'))
            ->onEnter('playing', $note('enter'))
            ->transition(Transition::on('PLAY', 'stopped', 'playing')->withBefore($note('before'))->withAfter($note(
                'after',
            )))
            ->build();

        (new Machine($definition))->send('PLAY');

        self::assertSame(['before', 'exit', 'enter', 'after'], $log);
    }

    public function testFailingBeforeOrExitHookAbortsTheTransition(): void
    {
        $definition = Definition::builder()
            ->initial('stopped')
            ->states('stopped', 'playing')
            ->onExit('stopped', static function (): void {
                throw new RuntimeException('stay');
            })
            ->transition(Transition::on('PLAY', 'stopped', 'playing'))
            ->build();
        $machine = new Machine($definition);

        try {
            $machine->send('PLAY');
            self::fail('Expected an exception.');
        } catch (RuntimeException $e) {
            self::assertSame('stay', $e->getMessage());
        }

        self::assertSame('stopped', $machine->state());
    }

    public function testAfterHookFailureKeepsStateAndIsWrapped(): void
    {
        $transition = Transition::on('PLAY', 'stopped', 'playing')->withAfter(static function (): void {
            throw new RuntimeException('late');
        });
        $machine = new Machine(
            Definition::builder()->initial('stopped')->states('stopped', 'playing')->transition($transition)->build(),
        );

        try {
            $machine->send('PLAY');
            self::fail('Expected an exception.');
        } catch (AfterHookFailedException $e) {
            self::assertSame('late', $e->getPrevious()->getMessage());
            self::assertSame('PLAY', $e->context->event);
        }

        self::assertSame('playing', $machine->state());
    }

    public function testNestedSendFromBeforeHookIsRejectedButEnterHooksMayChain(): void
    {
        $holder = new \stdClass();
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b', 'c')
            ->onEnter('b', static function () use ($holder): void {
                $holder->machine->send('NEXT');
            })
            ->transition(
                Transition::on('GO', 'a', 'b'),
                Transition::on('NEXT', 'b', 'c'),
                Transition::on('BAD', 'a', 'b')->withBefore(static function () use ($holder): void {
                    $holder->machine->send('GO');
                }),
            )
            ->build();
        $holder->machine = new Machine($definition);

        try {
            $holder->machine->send('BAD');
            self::fail('Expected an exception.');
        } catch (ConcurrentTransitionException $e) {
            self::assertSame('a', $holder->machine->state());
        }

        self::assertSame('c', $holder->machine->send('GO')->state());
    }

    public function testSendIfAllowedAndTryTransition(): void
    {
        $machine = new Machine($this->video('stopped', [
            Transition::on('VETO', 'stopped', 'playing')->withGuard(static function (): bool {
                return false;
            }),
        ]));

        self::assertFalse($machine->sendIfAllowed('STOP'));
        self::assertInstanceOf(GuardRejectedException::class, $machine->tryTransition('VETO')->failure);
        self::assertInstanceOf(TransitionNotAllowedException::class, $machine->tryTransition('STOP')->failure);
        self::assertInstanceOf(UnknownEventException::class, $machine->tryTransition('NOPE')->failure);

        $result = $machine->tryTransition('PLAY');
        self::assertTrue($result->ok());
        self::assertSame('playing', $result->state);
    }

    public function testSendAllAndOptInHistory(): void
    {
        $machine = new Machine($this->video(), null, true);

        $machine->sendAll(['PLAY', ['PAUSE', ['why' => 'test']]]);

        self::assertSame('paused', $machine->state());
        self::assertSame(['PLAY', 'PAUSE'], array_map(static function (TransitionContext $c): string {
            return $c->event;
        }, $machine->history()));
        self::assertSame([], (new Machine($this->video()))->sendAll(['PLAY'])->history());
    }

    public function testDrivesStateOwnedByAnotherObjectAndValidatesIt(): void
    {
        $order = new \stdClass();
        $order->status = 'stopped';
        $machine = new Machine(
            $this->video(),
            new CallbackStore(static function () use ($order): string {
                return $order->status;
            }, static function (string $s) use ($order): void {
                $order->status = $s;
            }),
        );

        $machine->send('PLAY');
        self::assertSame('playing', $order->status);

        $order->status = 'ghost';
        try {
            $machine->send('STOP');
            self::fail('Expected an exception.');
        } catch (UnknownStateException $e) {
            self::assertStringContainsString('ghost', $e->getMessage());
        }

        $this->expectException(UnknownStateException::class);
        new Machine($this->video(), new InMemoryStore('ghost'));
    }

    public function testAtomicStoreRefusesAWriteThatRacesWithAnotherWriter(): void
    {
        $status = 'stopped';
        $store = new AtomicCallbackStore(static function () use (&$status): string {
            return $status;
        }, static function (string $expected, string $new) use (&$status): bool {
            if ($status !== $expected) {
                return false;
            }
            $status = $new;

            return true;
        });
        $machine = new Machine(
            $this->video('stopped', [
                Transition::on('GO', 'stopped', 'playing')->withBefore(static function () use (&$status): void {
                    $status = 'paused'; // another writer, bypassing the machine
                }),
            ]),
            $store,
        );

        try {
            $machine->send('GO');
            self::fail('Expected an exception.');
        } catch (ConcurrentTransitionException $e) {
            self::assertSame('paused', $status);
        }
    }
}
