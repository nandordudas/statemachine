<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Document;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\CallbackStore;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;

/**
 * Approval workflow over a domain object (anything with a public `status` string):
 *
 *   draft ──SUBMIT──▶ review ──APPROVE──▶ published
 *     ▲                  │                    │
 *     └──────REJECT──────┘                    └──ARCHIVE──▶ archived
 *
 *  - SUBMIT by a trusted author skips review (two transitions for one event, guard decides)
 *  - APPROVE needs the `approver` role in the payload, and never by the author themselves
 *  - `onExit(review)` clears the reviewer assignment; `onEnter(published)` notifies subscribers
 *  - the machine drives `$document->status` through a CallbackStore, so the entity stays the
 *    single source of truth (no separate state column to keep in sync).
 */
final class DocumentFlow
{
    /**
     * @param callable(TransitionContext): mixed $notifySubscribers
     */
    public static function definition(callable $notifySubscribers): Definition
    {
        $isApprover = static fn(TransitionContext $c): bool => ($c->payload['role'] ?? null) === 'approver'
            && ($c->payload['actor'] ?? null) !== ($c->payload['author'] ?? null);

        return Definition::builder()
            ->initial('draft')
            ->states('draft', 'review', 'published', 'archived')
            ->onEnter('published', $notifySubscribers)
            ->transition(
                Transition::on('SUBMIT', 'draft', 'published')
                    ->withGuard(static fn(TransitionContext $c): bool => ($c->payload['trustedAuthor'] ?? false) === true),
                Transition::on('SUBMIT', 'draft', 'review'),
                Transition::on('APPROVE', 'review', 'published')->withGuard($isApprover),
                Transition::on('REJECT', 'review', 'draft')->withGuard($isApprover),
                Transition::on('ARCHIVE', 'published', 'archived'),
            )
            ->build();
    }

    public static function machineFor(object $document, callable $notifySubscribers): Machine
    {
        return new Machine(
            self::definition($notifySubscribers),
            new CallbackStore(
                static fn(): string => $document->status,
                static function (string $status) use ($document): void {
                    $document->status = $status;
                },
            ),
        );
    }
}
