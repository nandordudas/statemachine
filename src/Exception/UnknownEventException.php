<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use DomainException;

final class UnknownEventException extends DomainException implements StateMachineException
{
    public static function named(string $event): self
    {
        return new self(sprintf('Event "%s" is not defined.', $event));
    }
}
