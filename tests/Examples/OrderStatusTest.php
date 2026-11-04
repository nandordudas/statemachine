<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Examples;

use Dudas\StateMachine\Examples\OrderStatus\OrderFlow;
use Dudas\StateMachine\Examples\OrderStatus\OrderStatus as S;
use Dudas\StateMachine\Examples\OrderStatus\StatusGroup as G;
use Dudas\StateMachine\Exception\GuardRejectedException;
use Dudas\StateMachine\Exception\TransitionNotAllowedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderStatusTest extends TestCase
{
    public function testEveryStatusBelongsToTheGroupOfTheSeedData(): void
    {
        self::assertCount(31, S::cases()); // 30 enabled order_status rows + New
        self::assertCount(8, S::inGroup(G::Incoming));
        self::assertCount(14, S::inGroup(G::Serving));
        self::assertSame([S::Completed], S::inGroup(G::Served));
        self::assertCount(5, S::inGroup(G::Inactive));
        self::assertSame([S::SentBack], S::inGroup(G::SentBack));
        self::assertSame([S::Returned], S::inGroup(G::Returned));
    }

    /**
     * The machine must agree with today's validation for every (old, new, pickup) combination.
     *
     * @return iterable<string, array{S, S, bool}>
     */
    public static function everyMovement(): iterable
    {
        foreach (S::cases() as $old) {
            foreach (S::cases() as $new) {
                if ($new === S::New) {
                    continue;
                }

                foreach ([false, true] as $pickup) {
                    yield sprintf('%s -> %s%s', $old->value, $new->value, $pickup ? ' (pickup)' : '') => [
                        $old,
                        $new,
                        $pickup,
                    ];
                }
            }
        }
    }

    #[DataProvider('everyMovement')]
    public function testMachineMatchesTheCurrentLogAnRules(S $old, S $new, bool $pickup): void
    {
        $groups = [];
        foreach (S::cases() as $status) {
            $groups[$status->value] = $status->group()->value;
        }

        $expected = LegacyOrderStatusRule::allows($old === S::New ? '' : $old->value, $new->value, $pickup, $groups);

        self::assertSame($expected, OrderFlow::machine($old)->can($new, ['localPickup' => $pickup]));
    }

    public function testNewOrdersMayStartIncomingServingOrInactiveButNeverServedOrReturned(): void
    {
        $machine = OrderFlow::machine();

        foreach ([S::Processing, S::OnHold, S::ShipperPending, S::Sendable, S::Forgone, S::Failed] as $start) {
            self::assertTrue($machine->can($start), $start->value);
        }
        foreach ([S::Completed, S::SentBack, S::Returned] as $start) {
            self::assertFalse($machine->can($start), $start->value);
        }
    }

    public function testRegularShippedOrderLifecycle(): void
    {
        $machine = OrderFlow::machine(S::ShipperPending);

        foreach ([S::Processing, S::Servable, S::Sendable, S::ToPrint, S::Printed, S::Completed] as $status) {
            $machine->send($status);
            self::assertTrue($machine->is($status));
        }

        self::assertSame(G::Served, OrderFlow::groupOf($machine));

        $machine->send(S::Returned);

        self::assertSame(G::Returned, OrderFlow::groupOf($machine));
    }

    public function testLocalPickupOrderLifecycle(): void
    {
        $machine = OrderFlow::machine(S::Processing);

        $machine->send(S::ShopPackable, ['localPickup' => true]);
        $machine->send(S::ShopReceivable, ['localPickup' => true]);
        $machine->send(S::Completed, ['localPickup' => true]);

        self::assertSame(G::Served, OrderFlow::groupOf($machine));
    }

    public function testLocalPickupOrderCannotBeCompletedBeforeItIsReceivable(): void
    {
        $machine = OrderFlow::machine(S::ShopPackable);

        $this->expectException(GuardRejectedException::class);

        $machine->send(S::Completed, ['localPickup' => true]);
    }

    public function testLocalPickupOrderCannotFallBackToSendableOnceItIsPacked(): void
    {
        $this->expectException(GuardRejectedException::class);

        OrderFlow::machine(S::ShopPackable)->send(S::Sendable, ['localPickup' => true]);
    }

    public function testShopStatusesAreOnlyForLocalPickupOrders(): void
    {
        $machine = OrderFlow::machine(S::Processing);

        self::assertFalse($machine->can(S::ShopPackable));
        self::assertTrue($machine->can(S::ShopPackable, ['localPickup' => true]));
    }

    public function testCompletedCanOnlyBeForgoneOrReturned(): void
    {
        $machine = OrderFlow::machine(S::Completed);

        self::assertEqualsCanonicalizing([S::Forgone->value, S::Returned->value], $machine->availableEvents());
    }

    public function testSentBackCanOnlyBeReturned(): void
    {
        self::assertEqualsCanonicalizing(
            [S::Returned->value, S::SentBack->value],
            OrderFlow::machine(S::SentBack)->availableEvents(),
        );
    }

    public function testReturnedIsTerminal(): void
    {
        self::assertSame([S::Returned->value], OrderFlow::machine(S::Returned)->availableEvents());
    }

    public function testInactiveOrdersCanBeReopenedAsIncomingOnly(): void
    {
        $machine = OrderFlow::machine(S::Trash);

        self::assertTrue($machine->can(S::Processing));
        self::assertFalse($machine->can(S::Sendable));
        self::assertFalse($machine->can(S::Completed));
    }

    public function testServingStatusesAreMutuallyInterchangeable(): void
    {
        foreach (S::inGroup(G::Serving) as $from) {
            foreach (S::inGroup(G::Serving) as $to) {
                if (in_array($to, [S::ShopPackable, S::ShopReceivable], true)) {
                    continue; // local pickup only
                }
                if ($from === S::ShopPackable && $to === S::Servable) {
                    continue;
                }
                self::assertTrue(OrderFlow::machine($from)->can($to), "{$from->value} -> {$to->value}");
            }
        }
    }

    public function testDisallowedGroupJumpIsNotAGuardRejection(): void
    {
        try {
            OrderFlow::machine(S::Trash)->send(S::Completed);
            self::fail('Expected an exception.');
        } catch (TransitionNotAllowedException $e) {
            self::assertSame(TransitionNotAllowedException::class, $e::class);
        }
    }

    public function testFailedMovementKeepsTheStatus(): void
    {
        $machine = OrderFlow::machine(S::Printed);

        self::assertFalse($machine->can(S::Returned));
        self::assertTrue($machine->is(S::Printed));
    }
}
