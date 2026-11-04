<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\OrderStatus;

use Dudas\StateMachine\Examples\OrderStatus\OrderStatus as S;
use Dudas\StateMachine\Examples\OrderStatus\StatusGroup as G;

/**
 * Which status changes LogAn allows, expressed on status groups
 * (StatusObserverService::$allowedMovements and its status-level exceptions).
 */
final class GroupRules
{
    /** @var list<array{G, G}> */
    private const array MOVEMENTS = [
        [G::None,     G::Incoming],
        [G::None,     G::Inactive],
        [G::None,     G::Serving],
        [G::Incoming, G::Serving],
        [G::Serving,  G::Incoming],
        [G::Incoming, G::Inactive],
        [G::Inactive, G::Incoming],
        [G::Serving,  G::Inactive],
        [G::Serving,  G::Served],
    ];

    public static function allows(S $from, S $to): bool
    {
        // `completed` is the only served status and is left by status, not by group.
        if ($from === S::Completed) {
            return $to === S::Forgone || $to === S::Returned;
        }

        // A parcel that came back after the customer withdrew is taken in like a completed one.
        if ($from === S::SentBack && $to === S::Returned) {
            return true;
        }

        // Same-group moves, including a status to itself, are allowed on purpose: this mirrors
        // StatusObserverService, and LegacyOrderStatusRule pins it for every pair.
        if ($from->group() === $to->group()) {
            return true;
        }

        return in_array([$from->group(), $to->group()], self::MOVEMENTS, true);
    }

    private function __construct() {}
}
