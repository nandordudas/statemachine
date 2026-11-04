<?php

declare(strict_types=1);

namespace Dudas\StateMachine;

use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * States and events are plain strings (PHP 7.2 has no enums; use class constants for type-safe names).
 */
final class Name
{
    /**
     * @param mixed $value
     */
    public static function of($value): string
    {
        if (!is_string($value)) {
            throw new InvalidDefinitionException(sprintf(
                'States and events must be strings, %s given.',
                is_object($value) ? get_class($value) : gettype($value),
            ));
        }

        return $value;
    }

    /**
     * @param string|string[] $values
     * @return string[]
     */
    public static function listOf($values): array
    {
        if (is_string($values)) {
            return [$values];
        }

        if (!is_array($values)) {
            throw new InvalidDefinitionException('Expected a name or a list of names.');
        }

        return array_values(array_unique(array_map([self::class, 'of'], $values)));
    }

    private function __construct() {}
}
