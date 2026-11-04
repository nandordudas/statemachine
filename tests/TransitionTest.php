<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Tests\Fixtures\VideoEvent;
use Dudas\StateMachine\Tests\Fixtures\VideoState;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use PHPUnit\Framework\TestCase;

use function Dudas\StateMachine\on;

final class TransitionTest extends TestCase
{
    public function testNormalisesEnumsAndSingleSourceToStrings(): void
    {
        $transition = Transition::on(VideoEvent::Play, VideoState::Stopped, VideoState::Playing);

        self::assertSame('PLAY', $transition->event);
        self::assertSame(['stopped'], $transition->from);
        self::assertSame('playing', $transition->to);
    }

    public function testDeduplicatesSources(): void
    {
        self::assertSame(['a', 'b'], Transition::on('E', ['a', 'b', 'a'], 'c')->from);
    }

    public function testRejectsEmptyParts(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        Transition::on('', 'a', 'b');
    }

    public function testRejectsEmptySourceList(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        /** @phpstan-ignore argument.type */
        new Transition('E', [], 'b');
    }

    public function testWithersReturnNewInstancesAndLeaveTheOriginalUntouched(): void
    {
        $plain = Transition::on('E', 'a', 'b');
        $guarded = $plain->withGuard(static fn(): bool => false);

        self::assertNotSame($plain, $guarded);
        self::assertCount(0, $plain->guards);
        self::assertCount(1, $guarded->guards);
        self::assertTrue($plain->permits('a'));
        self::assertFalse($guarded->permits('a'));
    }

    public function testPermitsOnlyFromDeclaredSources(): void
    {
        $transition = Transition::on('E', ['a', 'b'], 'c');

        self::assertTrue($transition->permits('b'));
        self::assertFalse($transition->permits('c'));
    }

    public function testAllGuardsMustAllowAndSeeTheContext(): void
    {
        $seen = null;
        $transition = Transition::on('E', 'a', 'b')->withGuard(static function (TransitionContext $c) use (
            &$seen,
        ): bool {
            $seen = $c;

            return true;
        })->withGuard(static fn(TransitionContext $c): bool => (bool) ($c->payload['ok'] ?? false));

        self::assertFalse($transition->permits('a'));
        self::assertTrue($transition->permits('a', ['ok' => true]));
        self::assertEquals(new TransitionContext('E', 'a', 'b', ['ok' => true]), $seen);
    }

    public function testConstructorWrapsClosures(): void
    {
        $transition = new Transition('GO', 'a', 'b', guards: [static fn(): bool => false]);

        self::assertFalse($transition->permits('a'));
    }

    public function testAbortHooksAreAddedImmutably(): void
    {
        $plain = Transition::on('GO', 'a', 'b');
        $compensating = $plain->withAbort(static fn() => null);

        self::assertSame([], $plain->abort);
        self::assertCount(1, $compensating->abort);
        self::assertCount(2, on('GO', 'a', 'b', abort: [static fn() => null, static fn() => null])->abort);
    }
}
