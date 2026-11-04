<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Tests\Fixtures\VideoState as S;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use PHPUnit\Framework\TestCase;

use function Dudas\StateMachine\machine;
use function Dudas\StateMachine\on;

final class MetaTest extends TestCase
{
    public function testTransitionMetaIsMergedImmutably(): void
    {
        $plain = Transition::on('GO', 'a', 'b');
        $labelled = $plain->withMeta(['label' => 'Go', 'style' => 'primary'])->withMeta(['style' => 'danger']);

        self::assertSame([], $plain->meta);
        self::assertSame(['label' => 'Go', 'style' => 'danger'], $labelled->meta);
        self::assertSame(['label' => 'Go'], on('GO', 'a', 'b', meta: ['label' => 'Go'])->meta);
    }

    public function testGuardsAndHooksSeeTheTransitionsMeta(): void
    {
        $seen = [];
        $machine = machine(initial: 'a', states: ['a', 'b'], transitions: [
            on(
                'GO',
                'a',
                'b',
                guard: static fn(TransitionContext $c): bool => ($c->payload['role'] ?? null) === $c->meta['role'],
                after: static function (TransitionContext $c) use (&$seen): void {
                    $seen = $c->meta;
                },
                meta: ['role' => 'editor'],
            ),
        ]);

        self::assertFalse($machine->can('GO', ['role' => 'guest']));

        $machine->send('GO', ['role' => 'editor']);
        self::assertSame(['role' => 'editor'], $seen);
    }

    public function testStatesCarryMeta(): void
    {
        $definition = Definition::builder()
            ->initial(S::Stopped)
            ->states(...S::cases())
            ->meta(S::Playing, ['label' => 'Now playing'])
            ->meta(S::Playing, ['color' => 'green'])
            ->transition(Transition::on('PLAY', S::Stopped, S::Playing))
            ->build();

        self::assertSame(['label' => 'Now playing', 'color' => 'green'], $definition->metaFor(S::Playing));
        self::assertSame(['label' => 'Now playing', 'color' => 'green'], $definition->metaFor('playing'));
        self::assertSame([], $definition->metaFor(S::Paused));
        self::assertSame(['x' => 1], machine('a', ['a'], [], stateMeta: ['a' => ['x' => 1]])->definition->metaFor('a'));
    }

    public function testMetaForAnUndeclaredStateIsRejected(): void
    {
        $this->expectException(InvalidDefinitionException::class);
        $this->expectExceptionMessage('Metadata refers to undeclared state "ghost".');

        new Definition('a', ['a'], [], stateMeta: ['ghost' => []]);
    }

    public function testAvailableTransitionsHandTheirMetaToAUi(): void
    {
        $machine = new Machine(new Definition('review', ['review', 'published', 'draft'], [
            on('APPROVE', 'review', 'published', guard: static fn(): bool => false, meta: ['label' => 'Approve']),
            on('APPROVE', 'review', 'published', meta: ['label' => 'Approve anyway']),
            on('REJECT', 'review', 'draft', meta: ['label' => 'Send back']),
            on('EDIT', 'draft', 'review'),
        ]));

        $labels = array_map(
            static fn(Transition $t): mixed => $t->meta['label'] ?? null,
            $machine->availableTransitions(),
        );

        self::assertSame(['Approve anyway', 'Send back'], $labels);
    }

    public function testDiagramsShowLabels(): void
    {
        $definition = Definition::builder()
            ->initial('draft')
            ->states('draft', 'review')
            ->meta('review', ['label' => 'In "review"'])
            ->transition(
                Transition::on('SUBMIT', 'draft', 'review')->withMeta(['label' => 'Submit']),
                Transition::on('REJECT', 'review', 'draft')->withGuard(static fn(): bool => true),
            )
            ->build();

        $mermaid = $definition->toMermaid();
        self::assertStringContainsString("state \"In 'review'\" as review", $mermaid);
        self::assertStringContainsString('draft --> review : Submit (SUBMIT)', $mermaid);
        self::assertStringContainsString('review --> draft : REJECT [guard]', $mermaid);

        $dot = $definition->toDot();
        self::assertStringContainsString('"review" [shape=circle, label="In \"review\""];', $dot);
        self::assertStringContainsString('"draft" -> "review" [label="Submit (SUBMIT)"];', $dot);
    }
}
