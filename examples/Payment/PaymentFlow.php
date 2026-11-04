<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\Payment;

use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Examples\Payment\PaymentEvent as E;
use Dudas\StateMachine\Examples\Payment\PaymentState as S;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;

/**
 * Card payment lifecycle, the way a payment gateway integration needs it:
 *
 *  - AUTHORIZE branches on the gateway's answer (conditional branching: first passing guard wins)
 *  - REFUND is only allowed for a captured payment and never above the captured amount (guard)
 *  - `onEnter(Captured)` books the revenue; it runs after the status is written (so a crash
 *    surfaces as AfterHookFailedException instead of silently losing the new status)
 *  - the status lives on a database row, so the store is atomic: two workers handling the
 *    same webhook cannot both win.
 */
final class PaymentFlow
{
    /**
     * @param callable(TransitionContext): mixed $bookRevenue
     */
    public static function definition(callable $bookRevenue): Definition
    {
        return Definition::builder()
            ->initial(S::Pending)
            ->states(...S::cases())
            ->onEnter(S::Captured, $bookRevenue)
            ->transition(
                Transition::on(E::Authorize, S::Pending, S::Authorized)
                    ->withGuard(static fn(TransitionContext $c): bool => ($c->payload['gatewayApproved'] ?? false) === true),
                Transition::on(E::Authorize, S::Pending, S::Declined),
                Transition::on(E::Capture, S::Authorized, S::Captured),
                Transition::on(E::Void, S::Authorized, S::Voided),
                Transition::on(E::Refund, S::Captured, S::Refunded)
                    ->withGuard(static fn(TransitionContext $c): bool => ($c->payload['amount'] ?? 0) <= ($c->payload['captured'] ?? 0)),
            )
            ->build();
    }

    public static function machine(callable $bookRevenue, ?AtomicStateStore $store = null): Machine
    {
        return new Machine(self::definition($bookRevenue), $store);
    }
}
