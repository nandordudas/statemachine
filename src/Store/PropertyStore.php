<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Name;
use ReflectionProperty;

/**
 * Keeps the state in a (string) property of an object, public or not:
 *
 *     new PropertyStore($order, 'status');
 */
final class PropertyStore implements StateStore
{
    /** @var object */
    private $target;

    /** @var ReflectionProperty */
    private $property;

    public function __construct(object $target, string $property = 'status')
    {
        if (!property_exists($target, $property)) {
            throw new InvalidDefinitionException(sprintf('%s has no property "%s".', get_class($target), $property));
        }

        $this->target = $target;
        $this->property = new ReflectionProperty($target, $property);
        if (version_compare(PHP_VERSION, '8.1.0', '<')) {
            $this->property->setAccessible(true); // a no-op since 8.1 and deprecated in 8.5
        }
    }

    public function get(): string
    {
        // Typed properties (7.4+) can be uninitialized: report that as a missing state, not a raw Error.
        if (method_exists($this->property, 'isInitialized') && !$this->property->isInitialized($this->target)) {
            throw UnknownStateException::missing();
        }

        $value = $this->property->getValue($this->target);

        if ($value === null) {
            throw UnknownStateException::missing();
        }

        return Name::of($value);
    }

    public function set(string $state): void
    {
        $this->property->setValue($this->target, $state);
    }
}
