<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Tests\Fixtures\VideoEvent as E;
use Dudas\StateMachine\Tests\Fixtures\VideoState as S;
use Dudas\StateMachine\TransitionContext;
use PHPUnit\Framework\TestCase;

use function Dudas\StateMachine\machine;
use function Dudas\StateMachine\on;

final class HelpersTest extends TestCase
{
    public function testMachineHelperBuildsARunnableMachine(): void
    {
        $log = [];
        $video = machine(
            initial: S::Stopped,
            states: S::class,
            transitions: [
                on(E::Play, from: S::Stopped, to: S::Playing, after: static function (TransitionContext $c) use (
                    &$log,
                ): void {
                    $log[] = "after:{$c->event}";
                }),
                on(
                    E::Stop,
                    S::Playing,
                    S::Stopped,
                    guard: static fn(TransitionContext $c): bool => ($c->payload['allowed'] ?? false) === true,
                ),
            ],
            onEnter: [
                S::Playing->value => static function () use (&$log): void {
                    $log[] = 'enter:playing';
                },
            ],
        );

        $video->send(E::Play);

        self::assertSame(['enter:playing', 'after:PLAY'], $log);
        self::assertFalse($video->can(E::Stop));
        self::assertTrue($video->can(E::Stop, ['allowed' => true]));
    }

    public function testOnAcceptsListsOfGuardsAndHooks(): void
    {
        $transition = on('GO', 'a', 'b', guard: [static fn(): bool => true, static fn(): bool => false]);

        self::assertFalse($transition->permits('a'));
        self::assertCount(2, $transition->guards);
    }

    public function testFromTableInfersStates(): void
    {
        $definition = Definition::fromTable('draft', [
            ['SUBMIT', 'draft', 'review'],
            ['APPROVE', 'review', 'published'],
            ['ARCHIVE', ['review', 'published'], 'archived'],
        ]);

        self::assertSame(['draft', 'review', 'published', 'archived'], $definition->states);
        self::assertSame('published', new Machine($definition)->send('SUBMIT')->send('APPROVE')->state);
    }
}
