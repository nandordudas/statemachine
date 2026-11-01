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
}
