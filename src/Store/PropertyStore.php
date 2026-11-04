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
 *
 * Not atomic: two processes that loaded the same entity can both apply a transition. Give
 * `machineFor()` a `compareAndSet` callback, or wrap the store in a {@see LockingStore}, for shared rows.
 */
final readonly class PropertyStore implements StateStore
{
    private ReflectionProperty $property;

    /** @var class-string<BackedEnum>|null the property's backed enum type, if it has one */
    private ?string $enum;

    public function __construct(
        private object $target,
        string $property = 'status',
    ) {
        if (!property_exists($target, $property)) {
            throw new InvalidDefinitionException(sprintf('%s has no property "%s".', $target::class, $property));
        }

        $this->property = new ReflectionProperty($target, $property);

        if ($this->property->isReadOnly()) {
            throw new InvalidDefinitionException(sprintf(
                'Property "%s" of %s is readonly; the machine cannot write the state into it.',
                $property,
                $target::class,
            ));
        }

        $type = $this->property->getType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $this->enum = $name !== null && enum_exists($name) && is_subclass_of($name, BackedEnum::class)
            ? $name
            : null;
    }

    /**
     * Fails fast when the property cannot hold every state, instead of in the middle of a transition.
     *
     * @param list<string> $states
     * @throws InvalidDefinitionException
     */
    public function assertCanHold(array $states): void
    {
        if ($this->enum === null) {
            return;
        }

        foreach ($states as $state) {
            if ($this->enum::tryFrom($state) === null) {
                throw new InvalidDefinitionException(sprintf(
                    'State "%s" is not a case of %s, the type of property "%s".',
                    $state,
                    $this->enum,
                    $this->property->getName(),
                ));
            }
        }
    }

    public function get(): string
    {
        $value = $this->property->getValue($this->target);

        if ($value === null) {
            throw UnknownStateException::missing();
        }

        if (!is_string($value) && !$value instanceof BackedEnum) {
            throw new InvalidDefinitionException(sprintf(
                'Property "%s" must hold a string or a backed enum, %s found.',
                $this->property->getName(),
                get_debug_type($value),
            ));
        }

        return Name::of($value);
    }

    public function set(string $state): void
    {
        if ($this->enum === null) {
            $this->property->setValue($this->target, $state);

            return;
        }

        $this->property->setValue($this->target, $this->enum::tryFrom($state) ?? throw UnknownStateException::named(
            $state,
        ));
    }
}
