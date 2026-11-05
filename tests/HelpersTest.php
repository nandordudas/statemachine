<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Name;
use Dudas\StateMachine\Store\PropertyStore;
use Dudas\StateMachine\Tests\Fixtures\VideoEvent;
use Dudas\StateMachine\Tests\Fixtures\VideoState;
use Dudas\StateMachine\TransitionContext;
use PHPUnit\Framework\TestCase;

use function Dudas\StateMachine\allOf;
use function Dudas\StateMachine\anyOf;
use function Dudas\StateMachine\machine;
use function Dudas\StateMachine\machineFor;
use function Dudas\StateMachine\not;
use function Dudas\StateMachine\on;
use function Dudas\StateMachine\payloadIs;
use function Dudas\StateMachine\payloadsEqual;

final class HelpersTest extends TestCase
{
    private function context(array $payload): TransitionContext
    {
        return new TransitionContext('GO', 'a', 'b', $payload);
    }

    public function testMachineAndOnHelpers(): void
    {
        $log = [];
        $video = machine(
            'stopped',
            ['playing', 'stopped'],
            [
                on('PLAY', 'stopped', 'playing', [], [], static function (TransitionContext $c) use (&$log): void {
                    $log[] = 'after:' . $c->event;
                }),
                on('STOP', 'playing', 'stopped', static function (TransitionContext $c): bool {
                    return ($c->payload['allowed'] ?? false) === true;
                })
            ],
            ['playing' => static function () use (&$log): void {
                $log[] = 'enter';
            }]
        );

        $video->send('PLAY');

        self::assertSame(['enter', 'after:PLAY'], $log);
        self::assertFalse($video->can('STOP'));
        self::assertTrue($video->can('STOP', ['allowed' => true]));
    }

    public function testGuardCombinators(): void
    {
        $reviewer = allOf(payloadIs('role', 'approver'), not(payloadsEqual('actor', 'author')));

        self::assertTrue($reviewer->allows($this->context([
            'role' => 'approver',
            'actor' => 'bob',
            'author' => 'ann'
        ])));
        self::assertFalse($reviewer->allows($this->context([
            'role' => 'approver',
            'actor' => 'ann',
            'author' => 'ann'
        ])));
        self::assertFalse($reviewer->allows($this->context(['role' => 'editor', 'actor' => 'bob', 'author' => 'ann'])));

        $either = anyOf(payloadIs('admin', true), static function (TransitionContext $c): bool {
            return ($c->payload['n'] ?? 0) > 1;
        });
        self::assertTrue($either->allows($this->context(['admin' => true])));
        self::assertTrue($either->allows($this->context(['n' => 2])));
        self::assertFalse($either->allows($this->context([])));
    }

    public function testOnAcceptsListsOfGuards(): void
    {
        $transition = on('GO', 'a', 'b', [
            static function (): bool {
                return true;
            },
            static function (): bool {
                return false;
            }
        ]);

        self::assertFalse($transition->permits('a'));
        self::assertCount(2, $transition->guards);
    }

    public function testMachineForBindsAPropertyAndPropertyStoreValidates(): void
    {
        $entity = new class {
            /** @var string|null */
            private $status = 'stopped';

            public function status(): ?string
            {
                return $this->status;
            }
        };
        $flow = machineFor(Definition::fromTable('stopped', [['PLAY', 'stopped', 'playing']]), $entity);

        $flow->send('PLAY');
        self::assertSame('playing', $entity->status());

        try {
            new PropertyStore(new \stdClass(), 'nope');
            self::fail('Expected an exception.');
        } catch (InvalidDefinitionException $e) {
            self::assertStringContainsString('nope', $e->getMessage());
        }

        $empty = new class {
            /** @var string|null */
            public $status;
        };
        $this->expectException(UnknownStateException::class);
        (new PropertyStore($empty))->get();
    }

    public function testMachineListsTheStatesOfAConstantsClass(): void
    {
        $video = machine(VideoState::STOPPED, VideoState::class, [
            on(VideoEvent::PLAY, VideoState::STOPPED, VideoState::PLAYING),
            on(VideoEvent::PAUSE, VideoState::PLAYING, VideoState::PAUSED),
        ]);

        self::assertSame(['stopped', 'playing', 'paused'], $video->definition->states);
        self::assertSame(VideoState::PAUSED, $video->send(VideoEvent::PLAY)->send(VideoEvent::PAUSE)->state());
        self::assertSame(['PLAY', 'PAUSE', 'STOP'], Name::constantsOf(VideoEvent::class));
    }

    public function testConstantsOfRejectsAnUnknownClass(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        Name::constantsOf('No\\Such\\StateNames');
    }

    public function testConstantsOfRejectsAClassWithoutStringConstants(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        Name::constantsOf(\stdClass::class);
    }
}
