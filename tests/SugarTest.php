<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\PropertyStore;
use Dudas\StateMachine\Tests\Fixtures\VideoEvent as E;
use Dudas\StateMachine\Tests\Fixtures\VideoState as S;
use Dudas\StateMachine\Transition;
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

final class SugarTest extends TestCase
{
    private function context(array $payload): TransitionContext
    {
        return new TransitionContext('GO', 'a', 'b', $payload);
    }

    public function testGuardCombinators(): void
    {
        $reviewer = allOf(payloadIs('role', 'approver'), not(payloadsEqual('actor', 'author')));

        self::assertTrue($reviewer->allows($this->context([
            'role' => 'approver',
            'actor' => 'bob',
            'author' => 'ann',
        ])));
        self::assertFalse($reviewer->allows($this->context([
            'role' => 'approver',
            'actor' => 'ann',
            'author' => 'ann',
        ])));
        self::assertFalse($reviewer->allows($this->context(['role' => 'editor', 'actor' => 'bob', 'author' => 'ann'])));

        $either = anyOf(payloadIs('admin', true), static fn(TransitionContext $c): bool => ($c->payload['n'] ?? 0) > 1);
        self::assertTrue($either->allows($this->context(['admin' => true])));
        self::assertTrue($either->allows($this->context(['n' => 2])));
        self::assertFalse($either->allows($this->context([])));
    }

    public function testPayloadIsMatchesBackedEnumValues(): void
    {
        self::assertTrue(payloadIs('state', S::Playing)->allows($this->context(['state' => 'playing'])));
        self::assertTrue(payloadIs('state', S::Playing)->allows($this->context(['state' => S::Playing])));
        self::assertFalse(payloadIs('state', S::Playing)->allows($this->context(['state' => 'paused'])));
    }

    public function testCombinatorsWorkInsideTransitions(): void
    {
        $video = machine(initial: S::Stopped, states: S::class, transitions: [on(
            E::Play,
            S::Stopped,
            S::Playing,
            guard: allOf(payloadIs('licensed', true), not(payloadIs('banned', true))),
        )]);

        self::assertFalse($video->can(E::Play));
        self::assertFalse($video->can(E::Play, ['licensed' => true, 'banned' => true]));
        self::assertTrue($video->can(E::Play, ['licensed' => true]));
    }

    public function testMachineForBindsAnEnumTypedProperty(): void
    {
        $entity = new class {
            public S $status = S::Stopped;
        };
        $flow = machineFor(Definition::fromTable(S::Stopped, [[E::Play, S::Stopped, S::Playing]]), $entity);

        $flow->send(E::Play);

        self::assertSame(S::Playing, $entity->status);
    }

    public function testPropertyStoreHandlesStringAndPrivateProperties(): void
    {
        $entity = new class {
            private ?string $stage = 'stopped';

            public function stage(): ?string
            {
                return $this->stage;
            }
        };
        $store = new PropertyStore($entity, 'stage');

        self::assertSame('stopped', $store->get());
        $store->set('playing');
        self::assertSame('playing', $entity->stage());
    }

    public function testPropertyStoreRejectsMissingPropertiesAndNullState(): void
    {
        try {
            new PropertyStore(new \stdClass(), 'nope');
            self::fail('Expected an exception.');
        } catch (InvalidDefinitionException) {
        }

        $this->expectException(UnknownStateException::class);
        new PropertyStore(new class {
            public ?string $status = null;
        })->get();
    }

    public function testSendAllRunsEventsInOrderWithOptionalPayloads(): void
    {
        $seen = [];
        $video = machine(initial: S::Stopped, states: S::class, transitions: [
            on(E::Play, S::Stopped, S::Playing, after: static function (TransitionContext $c) use (&$seen): void {
                $seen[] = $c->payload;
            }),
            on(E::Pause, S::Playing, S::Paused),
        ]);

        $video->sendAll([[E::Play, ['via' => 'test']], E::Pause]);

        self::assertSame(S::Paused->value, $video->state);
        self::assertSame([['via' => 'test']], $seen);
    }

    public function testHistoryIsOptIn(): void
    {
        $transitions = [on(E::Play, S::Stopped, S::Playing), on(E::Stop, S::Playing, S::Stopped)];

        $quiet = machine(S::Stopped, S::class, $transitions);
        $quiet->sendAll([E::Play]);
        self::assertSame([], $quiet->history());

        $recorded = machine(S::Stopped, S::class, $transitions, history: true);
        $recorded->sendAll([E::Play, E::Stop]);
        self::assertSame(
            ['PLAY', 'STOP'],
            array_map(static fn(TransitionContext $c): string => $c->event, $recorded->history()),
        );
        self::assertSame(
            ['stopped', 'playing'],
            array_map(static fn(TransitionContext $c): string => $c->from, $recorded->history()),
        );
    }

    public function testMermaidAndDotExport(): void
    {
        $definition = Definition::builder()
            ->initial('draft')
            ->states('draft', 'in review')
            ->transition(
                Transition::on('SUBMIT', 'draft', 'in review'),
                Transition::on('REJECT', 'in review', 'draft')->withGuard(static fn(): bool => true),
            )
            ->build();

        $mermaid = $definition->toMermaid();
        self::assertStringContainsString('stateDiagram-v2', $mermaid);
        self::assertStringContainsString('state "in review" as in_review', $mermaid);
        self::assertStringContainsString('[*] --> draft', $mermaid);
        self::assertStringContainsString('draft --> in_review : SUBMIT', $mermaid);
        self::assertStringContainsString('in_review --> draft : REJECT [guard]', $mermaid);

        $dot = $definition->toDot();
        self::assertStringContainsString('"draft" [shape=doublecircle];', $dot);
        self::assertStringContainsString('"in review" -> "draft" [label="REJECT [guard]"];', $dot);
    }

    public function testUnreachableStatesFindsStructuralDeadEnds(): void
    {
        $definition = Definition::builder()
            ->initial('a')
            ->states('a', 'b', 'orphan')
            ->transition(Transition::on('GO', 'a', 'b'))
            ->build();

        self::assertSame(['orphan'], $definition->unreachableStates());
        self::assertSame([], Definition::fromTable('a', [['GO', 'a', 'b']])->unreachableStates());
    }
}
