<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Tests\Fixtures\VideoState;
use Dudas\StateMachine\Transition;

final class DefinitionTest extends VideoTestCase
{
    public function testExposesStatesEventsAndTransitions(): void
    {
        $definition = $this->video();

        self::assertSame('stopped', $definition->initial);
        self::assertSame(['playing', 'stopped', 'paused'], $definition->states);
        self::assertSame(['PLAY', 'STOP', 'PAUSE'], $definition->events());
        self::assertTrue($definition->hasEvent('PLAY'));
        self::assertFalse($definition->hasEvent('NOPE'));
        self::assertTrue($definition->hasState('paused'));
        self::assertCount(1, $definition->transitionsFor('STOP'));
    }

    public function testAcceptsEnums(): void
    {
        $definition = new Definition(VideoState::Stopped, VideoState::cases(), []);

        self::assertSame('stopped', $definition->initial);
        self::assertSame(['playing', 'stopped', 'paused'], $definition->states);
    }

    public function testInitialStateMustBeDeclared(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        new Definition('ghost', ['a'], []);
    }

    public function testTransitionsMustReferenceDeclaredStates(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('undeclared state "c"');

        new Definition('a', ['a', 'b'], [Transition::on('E', 'a', 'c')]);
    }

    public function testBuilderRequiresAnInitialState(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        Definition::builder()->states('a')->build();
    }

    public function testNeedsAtLeastOneState(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        new Definition('a', [], []);
    }

    public function testRejectsEmptyStateName(): void
    {
        $this->expectException(\Dudas\StateMachine\Exception\InvalidDefinitionException::class);

        new Definition('', [''], []);
    }

    public function testRejectsNonTransitionElements(): void
    {
        $this->expectException(\Dudas\StateMachine\Exception\InvalidDefinitionException::class);

        new Definition('a', ['a'], ['oops']);
    }

    public function testIndexesTransitionsBySourceStateInDefinitionOrder(): void
    {
        $definition = Definition::fromTable('a', [
            ['X', 'b', 'a'],
            ['Y', 'a', 'b'],
            ['X', 'a', 'c'],
            ['Y', 'a', 'c'],
        ]);

        self::assertSame(['X', 'Y'], $definition->eventsFrom('a'), 'events keep their definition order');
        self::assertSame(['b', 'c'], array_map(
            static fn(Transition $t): string => $t->to,
            $definition->transitionsFrom('a', 'Y'),
        ));
        self::assertSame([], $definition->transitionsFrom('c', 'X'));
        self::assertSame([], $definition->eventsFrom('c'));
    }
}
