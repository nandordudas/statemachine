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
        $this->property->setAccessible(true);
    }

    public function get(): string
    {
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
