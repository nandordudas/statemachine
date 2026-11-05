<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
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
        $machine = machine(
            initial: 'a',
            states: ['a', 'b'],
            transitions: [
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
            ],
        );

        self::assertFalse($machine->can('GO', ['role' => 'guest']));

        $machine->send('GO', ['role' => 'editor']);
        self::assertSame(['role' => 'editor'], $seen);
    }

    public function testDiagramsShowTransitionLabels(): void
    {
        $definition = Definition::builder()
            ->initial('draft')
            ->states('draft', 'review')
            ->transition(Transition::on('SUBMIT', 'draft', 'review')->withMeta([
                'label' => 'Submit',
            ]), Transition::on('REJECT', 'review', 'draft')->withGuard(static fn(): bool => true))
            ->build();

        $mermaid = $definition->toMermaid();
        self::assertStringContainsString('draft --> review : Submit (SUBMIT)', $mermaid);
        self::assertStringContainsString('review --> draft : REJECT [guard]', $mermaid);

        $dot = $definition->toDot();
        self::assertStringContainsString('"review" [shape=circle];', $dot);
        self::assertStringContainsString('"draft" -> "review" [label="Submit (SUBMIT)"];', $dot);
    }
}
