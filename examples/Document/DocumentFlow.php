<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Document;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Examples\Document\DocumentEvent as E;
use Dudas\StateMachine\Examples\Document\DocumentState as S;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\CallbackStore;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;

/**
 * Approval workflow over a {@see Document}:
 *
 *   draft ──SUBMIT──▶ review ──APPROVE──▶ published
 *     ▲                  │                    │
 *     └──────REJECT──────┘                    └──ARCHIVE──▶ archived
 *
 *  - SUBMIT by a trusted author skips review (two transitions for one event, guard decides)
 *  - APPROVE and REJECT need an {@see ApproverGuard}: the approver role, and not the author; it explains
 *    which rule failed, so `$flow->blockers(E::Approve, $payload)` can be shown to the user
 *  - transitions carry button labels in their metadata (`availableTransitions()` hands them to a UI)
 *  - `onExit(review)` clears the reviewer assignment; `onEnter(published)` notifies subscribers
 *  - the machine drives `$document->status` through a CallbackStore, so the entity stays the
 *    single source of truth (no separate state column to keep in sync).
 */
final class DocumentFlow
{
    /**
     * @param callable(TransitionContext): mixed $notifySubscribers
     * @param callable(TransitionContext): mixed $clearReviewer
     */
    public static function definition(callable $notifySubscribers, callable $clearReviewer): Definition
    {
        return Definition::builder()
            ->initial(S::Draft)
            ->states(...S::cases())
            ->onExit(S::Review, $clearReviewer)
            ->onEnter(S::Published, $notifySubscribers)
            ->meta(S::Review, ['label' => 'In review'])
            ->transition(
                Transition::on(E::Submit, S::Draft, S::Published)->withGuard(
                    static fn(TransitionContext $c): bool => ($c->payload['trustedAuthor'] ?? false) === true,
                ),
                Transition::on(E::Submit, S::Draft, S::Review),
                Transition::on(E::Approve, S::Review, S::Published)->withGuard(new ApproverGuard())->withMeta([
                    'label' => 'Approve',
                    'style' => 'primary',
                ]),
                Transition::on(E::Reject, S::Review, S::Draft)->withGuard(new ApproverGuard())->withMeta([
                    'label' => 'Send back',
                    'style' => 'danger',
                ]),
                Transition::on(E::Archive, S::Published, S::Archived),
            )
            ->build();
    }

    /**
     * @param callable(TransitionContext): mixed $notifySubscribers
     */
    public static function machineFor(Document $document, callable $notifySubscribers): Machine
    {
        return new Machine(
            self::definition($notifySubscribers, static function () use ($document): void {
                $document->reviewer = null;
            }),
            new CallbackStore(static fn(): S => $document->status, static function (string $status) use (
                $document,
            ): void {
                $document->status = S::from($status);
            }),
        );
    }
}
