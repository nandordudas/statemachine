<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Examples;

use Dudas\StateMachine\Blocker;
use Dudas\StateMachine\Examples\Document\Document;
use Dudas\StateMachine\Examples\Document\DocumentEvent;
use Dudas\StateMachine\Examples\Document\DocumentFlow;
use Dudas\StateMachine\Examples\Document\DocumentState;
use Dudas\StateMachine\Examples\Payment\PaymentEvent as E;
use Dudas\StateMachine\Examples\Payment\PaymentFlow;
use Dudas\StateMachine\Examples\Payment\PaymentState as S;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\TransitionContext;
use PHPUnit\Framework\TestCase;

final class RealWorldExamplesTest extends TestCase
{
    public function testPaymentHappyPathBooksRevenueOnce(): void
    {
        $booked = [];
        $payment = PaymentFlow::machine(static function (TransitionContext $c) use (&$booked): void {
            $booked[] = $c->payload['captured'];
        });

        $payment->send(E::Authorize, ['gatewayApproved' => true])->send(E::Capture, ['captured' => 40]);

        self::assertTrue($payment->is(S::Captured));
        self::assertSame([40], $booked);
    }

    public function testDeclinedAuthorizationEndsInDeclined(): void
    {
        $payment = PaymentFlow::machine(static fn() => null);

        self::assertSame('declined', $payment->send(E::Authorize, ['gatewayApproved' => false])->state);
        self::assertSame([], $payment->availableEvents());
    }

    public function testRefundCannotExceedTheCapturedAmount(): void
    {
        $payment = PaymentFlow::machine(static fn() => null);
        $payment->send(E::Authorize, ['gatewayApproved' => true])->send(E::Capture, ['captured' => 40]);

        self::assertFalse($payment->can(E::Refund, ['amount' => 50, 'captured' => 40]));
        self::assertTrue($payment->can(E::Refund, ['amount' => 40, 'captured' => 40]));

        $this->expectException(GuardRejectedException::class);
        $payment->send(E::Refund, ['amount' => 50, 'captured' => 40]);
    }

    public function testTwoWorkersCannotBothApplyTheSameWebhook(): void
    {
        $row = ['status' => 'pending'];
        // A closure (not an arrow function) so every store shares the same row by reference.
        $store = static function () use (&$row): AtomicCallbackStore {
            return new AtomicCallbackStore(static function () use (&$row): string {
                return $row['status'];
            }, static function (string $expected, string $new) use (&$row): bool {
                if ($row['status'] !== $expected) {
                    return false;
                }
                $row['status'] = $new;

                return true;
            });
        };

        $workerA = PaymentFlow::machine(static fn() => null, $store());
        $workerB = PaymentFlow::machine(static fn() => null, $store());

        $workerA->send(E::Authorize, ['gatewayApproved' => true]);

        // B reads the row fresh, so the replayed webhook is simply not allowed any more.
        self::assertFalse($workerB->can(E::Authorize, ['gatewayApproved' => true]));
        self::assertSame('authorized', $row['status']);
    }

    public function testDocumentWorkflowDrivesTheEntityStatus(): void
    {
        $document = new Document();
        $document->reviewer = 'bob';
        $notified = 0;
        $flow = DocumentFlow::machineFor($document, static function () use (&$notified): void {
            $notified++;
        });

        $flow->send(DocumentEvent::Submit);
        self::assertSame(DocumentState::Review, $document->status);

        self::assertFalse(
            $flow->can(DocumentEvent::Approve, ['role' => 'approver', 'actor' => 'ann', 'author' => 'ann']),
            'authors cannot approve their own work',
        );
        self::assertFalse($flow->can(DocumentEvent::Approve, [
            'role' => 'editor',
            'actor' => 'bob',
            'author' => 'ann',
        ]));
        self::assertFalse(
            $flow->can(DocumentEvent::Approve, ['role' => 'approver', 'author' => 'ann']),
            'an anonymous actor is not an approver',
        );

        $why = $flow->blockers(DocumentEvent::Approve, ['role' => 'editor', 'actor' => 'ann', 'author' => 'ann']);
        self::assertSame(
            ['Only approvers can review documents.', 'Authors cannot review their own documents.'],
            array_map(static fn(Blocker $blocker): string => $blocker->message, $why),
            'the guard says which rules fail',
        );
        self::assertSame(
            [DocumentEvent::Approve, DocumentEvent::Reject],
            array_map(
                DocumentEvent::from(...),
                $flow->availableEvents(['role' => 'approver', 'actor' => 'bob', 'author' => 'ann']),
            ),
        );

        $flow->send(DocumentEvent::Approve, ['role' => 'approver', 'actor' => 'bob', 'author' => 'ann']);

        self::assertSame(DocumentState::Published, $document->status);
        self::assertNull($document->reviewer, 'leaving review clears the assignment');
        self::assertSame(1, $notified);
    }

    public function testTrustedAuthorsSkipReview(): void
    {
        $document = new Document();

        DocumentFlow::machineFor($document, static fn() => null)->send(DocumentEvent::Submit, [
            'trustedAuthor' => true,
        ]);

        self::assertSame(DocumentState::Published, $document->status);
    }

    public function testRejectSendsTheDocumentBackToDraft(): void
    {
        $document = new Document(DocumentState::Review);

        DocumentFlow::machineFor($document, static fn() => null)->send(DocumentEvent::Reject, [
            'role' => 'approver',
            'actor' => 'bob',
            'author' => 'ann',
        ]);

        self::assertSame(DocumentState::Draft, $document->status);
    }
}
