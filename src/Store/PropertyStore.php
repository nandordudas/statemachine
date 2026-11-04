<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use BackedEnum;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Exception\InvalidDefinitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Name;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Keeps the state in a property of an object (entity, model, DTO): public or not, string or backed enum.
 * Writes convert back to the property's enum type, so the entity can never hold an invalid status.
 *
 *     new PropertyStore($order, 'status');
 */
final readonly class PropertyStore implements StateStore
{
    private ReflectionProperty $property;

    public function __construct(
        private object $target,
        string $property = 'status',
    ) {
        if (!property_exists($target, $property)) {
            throw new InvalidDefinitionException(sprintf('%s has no property "%s".', $target::class, $property));
        }

        $this->property = new ReflectionProperty($target, $property);
    }

    public function get(): string
    {
        $value = $this->property->getValue($this->target);

        return $value === null ? throw UnknownStateException::missing() : Name::of($value);
    }

    public function set(string $state): void
    {
        $type = $this->property->getType();
        $enum = $type instanceof ReflectionNamedType
        && enum_exists($type->getName())
        && is_subclass_of($type->getName(), BackedEnum::class)
            ? $type->getName()
            : null;

        $this->property->setValue($this->target, $enum !== null ? $enum::from($state) : $state);
    }
}
