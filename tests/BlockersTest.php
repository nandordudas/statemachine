<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests;

use Dudas\StateMachine\Blocker;
use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Guards\Not;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Tests\Fixtures\MachineRef;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use PHPUnit\Framework\TestCase;

use function Dudas\StateMachine\allOf;
use function Dudas\StateMachine\anyOf;
use function Dudas\StateMachine\because;
use function Dudas\StateMachine\not;
use function Dudas\StateMachine\on;
use function Dudas\StateMachine\payloadIs;

final class BlockersTest extends TestCase
{
    private function approval(Transition ...$approve): Machine
    {
        return new Machine(new Definition('review', ['review', 'published'], $approve));
    }

    /**
     * @param list<Blocker> $blockers
     * @return list<string>
     */
    private static function codes(array $blockers): array
    {
        return array_map(static fn(Blocker $b): string => $b->code, $blockers);
    }

    public function testAnAllowedEventHasNoBlockers(): void
    {
        $machine = $this->approval(on('APPROVE', 'review', 'published', guard: payloadIs('role', 'approver')));

        self::assertSame([], $machine->blockers('APPROVE', ['role' => 'approver']));
    }

    public function testBecauseExplainsAGuard(): void
    {
        $machine = $this->approval(on('APPROVE', 'review', 'published', guard: because(
            payloadIs('role', 'approver'),
            'Only approvers can approve.',
            'not_approver',
            [
                'role' => 'approver',
            ],
        )));

        $blockers = $machine->blockers('APPROVE', ['role' => 'editor']);

        self::assertCount(1, $blockers);
        self::assertSame('not_approver', $blockers[0]->code);
        self::assertSame('Only approvers can approve.', $blockers[0]->message);
        self::assertSame(['role' => 'approver'], $blockers[0]->parameters);
    }

    public function testAMessageMayDependOnTheContext(): void
    {
        $machine = $this->approval(on(
            'APPROVE',
            'review',
            'published',
            guard: because(
                static fn(TransitionContext $c): bool => ($c->payload['amount'] ?? 0) <= 100,
                static fn(TransitionContext $c): string => json_encode($c->payload['amount'] ?? null) . ' is over 100.',
            ),
        ));

        $blockers = $machine->blockers('APPROVE', ['amount' => 250]);
        self::assertSame('250 is over 100.', $blockers[0]->message);
    }

    public function testAllOfListsEveryFailingGuardNotOnlyTheFirst(): void
    {
        $machine = $this->approval(on(
            'APPROVE',
            'review',
            'published',
            guard: allOf(
                because(payloadIs('role', 'approver'), 'Needs the approver role.', 'role'),
                because(payloadIs('signed', true), 'Needs a signature.', 'signature'),
                because(payloadIs('paid', true), 'Needs payment.', 'payment'),
            ),
        ));

        self::assertSame(['role', 'signature'], self::codes($machine->blockers('APPROVE', ['paid' => true])));
    }

    public function testAnyOfExplainsOnlyWhenEveryAlternativeFails(): void
    {
        $guard = anyOf(
            because(payloadIs('role', 'approver'), 'Needs the approver role.', 'role'),
            because(payloadIs('role', 'admin'), 'Needs the admin role.', 'admin'),
        );
        $machine = $this->approval(on('APPROVE', 'review', 'published', guard: $guard));

        self::assertSame([], $machine->blockers('APPROVE', ['role' => 'admin']));
        self::assertSame(['role', 'admin'], self::codes($machine->blockers('APPROVE', ['role' => 'guest'])));
    }

    public function testAGuardWithoutAReasonIsReportedAsAGenericRejection(): void
    {
        $machine = $this->approval(on(
            'APPROVE',
            'review',
            'published',
            guard: [static fn(): bool => false, not(static fn(): bool => true)],
        ));

        $blockers = $machine->blockers('APPROVE');
        self::assertSame([Blocker::GUARD_REJECTED, Blocker::GUARD_REJECTED], self::codes($blockers));
        self::assertSame('closure', $blockers[0]->parameters['guard']);
        self::assertSame(Not::class, $blockers[1]->parameters['guard']);
    }

    public function testUnknownAndNotAllowedEventsAreExplainedToo(): void
    {
        $machine = $this->approval(on('APPROVE', 'review', 'published'), on('ARCHIVE', 'published', 'review'));

        self::assertSame([Blocker::UNKNOWN_EVENT], self::codes($machine->blockers('NOPE')));
        self::assertSame([Blocker::NOT_ALLOWED], self::codes($machine->blockers('ARCHIVE')));
    }

    public function testOnePermittedBranchMeansNoBlockersAndAllBranchesAreExplainedOtherwise(): void
    {
        $fastTrack = because(payloadIs('fast', true), 'Not a fast track.', 'fast');
        $approver = because(payloadIs('role', 'approver'), 'Not an approver.', 'role');
        $machine = $this->approval(
            on('APPROVE', 'review', 'published', guard: $fastTrack),
            on('APPROVE', 'review', 'published', guard: $approver),
        );

        self::assertSame([], $machine->blockers('APPROVE', ['role' => 'approver']));
        self::assertSame(['fast', 'role'], self::codes($machine->blockers('APPROVE')));
    }

    public function testARefusedSendCarriesTheReasons(): void
    {
        $machine = $this->approval(on(
            'APPROVE',
            'review',
            'published',
            guard: because(payloadIs('role', 'approver'), 'Only approvers can approve.', 'not_approver'),
        ));

        try {
            $machine->send('APPROVE');
            self::fail('Expected a guard rejection.');
        } catch (GuardRejectedException $e) {
            self::assertSame(['not_approver'], self::codes($e->blockers()));
            self::assertStringContainsString('Only approvers can approve.', $e->getMessage());
        }

        $result = $machine->tryTransition('APPROVE');
        self::assertSame(['not_approver'], self::codes($result->blockers()));
        self::assertSame([Blocker::UNKNOWN_EVENT], self::codes($machine->tryTransition('NOPE')->blockers()));
        self::assertSame([], $machine->tryTransition('APPROVE', ['role' => 'approver'])->blockers());
    }

    public function testExplainingGuardsMustNotSendEither(): void
    {
        $ref = new MachineRef();
        $ref->machine = $this->approval(on('APPROVE', 'review', 'published', guard: because(static function () use (
            $ref,
        ): bool {
            $ref->get()->send('APPROVE');

            return false;
        }, 'never shown')));

        $this->expectException(ConcurrentTransitionException::class);

        $ref->get()->blockers('APPROVE');
    }
}
