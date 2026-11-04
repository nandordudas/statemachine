<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
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

    public function testNeedsAtLeastOneStateAndNoEmptyNames(): void
    {
        try {
            new Definition('a', [], []);
            self::fail('Expected an exception.');
        } catch (InvalidDefinitionException $e) {
            self::assertStringContainsString('at least one state', $e->getMessage());
        }

        $this->expectException(InvalidDefinitionException::class);
        new Definition('', [''], []);
    }

    public function testRejectsNonTransitionElementsAndNonStringNames(): void
    {
        try {
            new Definition('a', ['a'], ['oops']);
            self::fail('Expected an exception.');
        } catch (InvalidDefinitionException $e) {
            self::assertStringContainsString('instances of', $e->getMessage());
        }

        $this->expectException(InvalidDefinitionException::class);
        new Definition('a', ['a', 1], []);
    }

    public function testStateHooksMustReferToDeclaredStates(): void
    {
        $this->expectException(InvalidDefinitionException::class);

        Definition::builder()
            ->initial('a')
            ->states('a')
            ->onEnter('ghost', static function () {})
            ->build();
    }

    public function testFromTableInfersStatesAndUnreachableStatesAreReported(): void
    {
        $definition = Definition::fromTable('draft', [
            ['SUBMIT', 'draft', 'review'],
            ['ARCHIVE', ['review', 'published'], 'archived'],
        ]);

        self::assertSame(['draft', 'review', 'published', 'archived'], $definition->states);
        self::assertSame(['published'], $definition->unreachableStates());
    }

    public function testMermaidAndDotExport(): void
    {
        $definition = Definition::builder()
            ->initial('draft')
            ->states('draft', 'in review')
            ->transition(
                Transition::on('SUBMIT', 'draft', 'in review'),
                Transition::on('REJECT', 'in review', 'draft')->withGuard(static function (): bool {
                    return true;
                }),
            )
            ->build();

        $mermaid = $definition->toMermaid();
        self::assertStringContainsString('state "in review" as in_review', $mermaid);
        self::assertStringContainsString('draft --> in_review : SUBMIT', $mermaid);
        self::assertStringContainsString('in_review --> draft : REJECT [guard]', $mermaid);
        self::assertStringContainsString('"draft" [shape=doublecircle];', $definition->toDot());
    }
}
