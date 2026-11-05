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
                is_object($value) ? get_class($value) : gettype($value)
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

    /**
     * The values of the public string constants of `$class`, in declaration order: the PHP 7.2 stand-in for an
     * enum's cases. `machine(VideoState::STOPPED, VideoState::class, ...)` uses it to list every state.
     *
     *     final class VideoState
     *     {
     *         const STOPPED = 'stopped';
     *         const PLAYING = 'playing';
     *     }
     *
     * @return string[]
     * @throws InvalidDefinitionException when the class does not exist or has no public string constant
     */
    public static function constantsOf(string $class): array
    {
        if (!class_exists($class) && !interface_exists($class)) {
            throw new InvalidDefinitionException(sprintf('Class "%s" does not exist.', $class));
        }

        $names = [];

        foreach ((new \ReflectionClass($class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();

            if ($constant->isPublic() && is_string($value)) {
                $names[] = $value;
            }
        }

        if ($names === []) {
            throw new InvalidDefinitionException(sprintf('%s has no public string constants to use as names.', $class));
        }

        return array_values(array_unique($names));
    }

    private function __construct() {}
}
