<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use PHPUnit\Framework\TestCase;

final class TransitionTest extends TestCase
{
    public function testNormalisesSingleSourceAndDeduplicates(): void
    {
        $transition = Transition::on('E', ['a', 'b', 'a'], 'c');

        self::assertSame('E', $transition->event);
        self::assertSame(['a', 'b'], $transition->from);
        self::assertSame('c', $transition->to);
        self::assertSame(['a'], Transition::on('E', 'a', 'b')->from);
    }

    public function testRejectsEmptyPartsAndSourceList(): void
    {
        try {
            Transition::on('', 'a', 'b');
            self::fail('Expected an exception.');
        } catch (InvalidDefinitionException $e) {
            self::assertStringContainsString('non-empty', $e->getMessage());
        }

        $this->expectException(InvalidDefinitionException::class);
        new Transition('E', [], 'b');
    }

    public function testWithersReturnNewInstancesAndLeaveTheOriginalUntouched(): void
    {
        $plain = Transition::on('E', 'a', 'b');
        $guarded = $plain->withGuard(static function (): bool {
            return false;
        });

        self::assertNotSame($plain, $guarded);
        self::assertCount(0, $plain->guards);
        self::assertCount(1, $guarded->guards);
        self::assertTrue($plain->permits('a'));
        self::assertFalse($guarded->permits('a'));
    }

    public function testAllGuardsMustAllowAndSeeTheContext(): void
    {
        $seen = null;
        $transition = Transition::on('E', 'a', 'b')->withGuard(static function (TransitionContext $c) use (
            &$seen
        ): bool {
            $seen = $c;

            return true;
        })->withGuard(static function (TransitionContext $c): bool {
            return (bool) ($c->payload['ok'] ?? false);
        });

        self::assertFalse($transition->permits('a'));
        self::assertTrue($transition->permits('a', ['ok' => true]));
        self::assertEquals(new TransitionContext('E', 'a', 'b', ['ok' => true]), $seen);
        self::assertFalse($transition->permits('z', ['ok' => true]));
    }

    public function testConstructorWrapsClosures(): void
    {
        $transition = new Transition('GO', 'a', 'b', [static function (): bool {
            return false;
        }]);

        self::assertFalse($transition->permits('a'));
    }
}
