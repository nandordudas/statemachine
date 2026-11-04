<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\OrderStatus;

use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\Examples\OrderStatus\OrderStatus as S;
use Dudas\StateMachine\TransitionContext;

/**
 * The local pickup rules of OrderModel::validateStatusTransition().
 * Reads `localPickup` (bool) from the event payload.
 */
final readonly class LocalPickupGuard implements Guard
{
    public function allows(TransitionContext $context): bool
    {
        $from = S::tryFrom($context->from);
        $to = S::tryFrom($context->to);

        if ($from === null || $to === null) {
            return false;
        }

        if (($context->payload['localPickup'] ?? false) === true) {
            return (
                !(in_array($to, [S::Sendable, S::Servable], true) && $from === S::ShopPackable)
                && !($to === S::Completed && $from !== S::ShopReceivable)
            );
        }

        return !in_array($to, [S::ShopPackable, S::ShopReceivable], true);
    }
}
