<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use BackedEnum;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * States and events are plain strings internally; backed enums are accepted
 * everywhere as a type-safe way to spell them.
 */
final class Name
{
    public static function of(string|BackedEnum $value): string
    {
        if (!$value instanceof BackedEnum) {
            return $value;
        }

        if (!is_string($value->value)) {
            throw new InvalidDefinitionException(sprintf(
                '%s is int-backed; only string-backed enums can name states and events.',
                $value::class,
            ));
        }

        return $value->value;
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

        $names = array_map(self::of(...), is_array($values) ? $values : iterator_to_array($values, false));

        return $names |> array_unique(...) |> array_values(...);
    }

    private function __construct() {}
}
