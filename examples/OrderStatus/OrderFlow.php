<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Examples\OrderStatus;

use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\InMemoryStore;
use Dudas\StateMachine\Transition;

/**
 * The order status matrix as a state machine. The event is the status to move to, so
 * `send(OrderStatus::Completed, ['localPickup' => true])` reads like LogAn's "set status".
 * Sources of each event come from {@see GroupRules}; the local pickup rules are a guard.
 */
final class OrderFlow
{
    public static function definition(): Definition
    {
        $transitions = [];

        foreach (OrderStatus::cases() as $target) {
            if ($target === OrderStatus::New) {
                continue;
            }

            $sources = array_values(array_filter(
                OrderStatus::cases(),
                static fn(OrderStatus $source): bool => GroupRules::allows($source, $target),
            ));

            if ($sources !== []) {
                $transitions[] = Transition::on($target, $sources, $target)->withGuard(new LocalPickupGuard());
            }
        }

        return new Definition(OrderStatus::New, OrderStatus::cases(), $transitions);
    }

    public static function machine(OrderStatus $current = OrderStatus::New): Machine
    {
        return new Machine(self::definition(), new InMemoryStore($current->value));
    }

    public static function groupOf(Machine $machine): StatusGroup
    {
        return OrderStatus::from($machine->state)->group();
    }

    private function __construct() {}
}
