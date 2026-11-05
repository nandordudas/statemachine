<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use InvalidArgumentException;

final class UnknownStateException extends InvalidArgumentException implements StateMachineException
{
    public static function named(string $state): self
    {
        return new self(sprintf('State "%s" is not part of the definition.', $state));
    }

    public static function notACase(string $state, string $enum): self
    {
        return new self(sprintf('State "%s" is not a case of %s.', $state, $enum));
    }

    public static function missing(): self
    {
        return new self('The state store holds no state.');
    }
}
