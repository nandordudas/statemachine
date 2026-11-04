<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

/**
 * Stand-ins for array_find/array_any/array_all, which need PHP 8.4.
 *
 * @internal
 */
final class Support
{
    /**
     * @param array<mixed> $items
     */
    public static function any(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $items
     */
    public static function all(array $items, callable $predicate): bool
    {
        foreach ($items as $item) {
            if (!$predicate($item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $items
     * @return mixed|null the first item the predicate accepts
     */
    public static function find(array $items, callable $predicate)
    {
        foreach ($items as $item) {
            if ($predicate($item)) {
                return $item;
            }
        }

        return null;
    }

    private function __construct() {}
}
