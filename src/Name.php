<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;

/**
 * States and events are plain strings internally; backed enums are accepted
 * everywhere as a type-safe way to spell them.
 */
final class Name
{
    public static function of(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    /**
     * @param string|BackedEnum|iterable<string|BackedEnum> $values
     * @return list<string>
     */
    public static function listOf(string|BackedEnum|iterable $values): array
    {
        if (is_string($values) || $values instanceof BackedEnum) {
            return [self::of($values)];
        }

        return array_values(array_unique(array_map(self::of(...), [...$values])));
    }

    private function __construct() {}
}
