<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\TransitionContext;
use Dudas\StateMachine\TransitionResult;
use Generator;
use PHPUnit\Framework\TestCase;

use function Dudas\StateMachine\on;

final class GeneratorTest extends TestCase
{
    private function machine(): Machine
    {
        return new Machine(
            new Definition(
                'draft',
                ['draft', 'review', 'published'],
                [
                    on('SUBMIT', 'draft', 'review'),
                    on(
                        'APPROVE',
                        'review',
                        'published',
                        guard: static fn(TransitionContext $c): bool => ($c->payload['ok'] ?? false) === true,
                    ),
                ],
            ),
        );
    }

    public function testStreamTriesEventsLazilyAndKeepsGoingAfterARefusal(): void
    {
        $machine = $this->machine();
        $pulled = 0;
        $events = (static function () use (&$pulled): Generator {
            foreach (['APPROVE', 'SUBMIT', ['APPROVE', ['ok' => true]], 'SUBMIT'] as $event) {
                $pulled++;

                yield $event;
            }
        })();

        $stream = $machine->stream($events);
        self::assertSame(0, $pulled, 'nothing is sent before the stream is consumed');

        $results = iterator_to_array($stream, false);

        $ok = array_map(static fn(TransitionResult $r): bool => $r->ok(), $results);
        self::assertSame([false, true, true, false], $ok);
        self::assertInstanceOf(TransitionNotAllowedException::class, $results[0]->failure);
        self::assertSame('published', $results[2]->state);
        self::assertSame('published', $machine->state);
    }

    public function testStreamCanBeStoppedEarly(): void
    {
        $machine = $this->machine();

        foreach ($machine->stream(['SUBMIT', 'APPROVE', ['APPROVE', ['ok' => true]]]) as $result) {
            if (!$result->ok()) {
                break;
            }
        }

        self::assertSame('review', $machine->state);
    }

    /**
     * @return Generator<int, string|array{string, array<string, mixed>}, TransitionResult, string>
     */
    private static function approval(): Generator
    {
        yield 'SUBMIT';
        $approved = yield 'APPROVE';

        if ($approved->failure instanceof GuardRejectedException) {
            yield ['APPROVE', ['ok' => true]];

            return 'approved on second try';
        }

        return 'approved at once';
    }

    public function testDriveFeedsEachResultBackIntoTheProcess(): void
    {
        $machine = $this->machine();

        $outcome = $machine->drive(self::approval());

        self::assertSame('approved on second try', $outcome);
        self::assertSame('published', $machine->state);
    }

    public function testShortestPathsYieldTheEventsThatReachEachState(): void
    {
        $definition = Definition::fromTable('a', [
            ['GO', 'a', 'b'],
            ['JUMP', 'a', 'c'],
            ['GO', 'b', 'c'],
            ['GO', 'c', 'd'],
        ]);

        self::assertSame(
            ['a' => [], 'b' => ['GO'], 'c' => ['JUMP'], 'd' => ['JUMP', 'GO']],
            iterator_to_array($definition->shortestPaths()),
        );
    }

    public function testShortestPathsLeaveOutUnreachableStates(): void
    {
        $definition = new Definition('a', ['a', 'b', 'island'], [on('GO', 'a', 'b')]);

        self::assertSame(['a', 'b'], array_keys(iterator_to_array($definition->shortestPaths())));
        self::assertSame(['island'], $definition->unreachableStates());
    }
}
