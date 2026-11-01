<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

class TransitionNotAllowedException extends LogicException implements StateMachineException
{
    final public function __construct(
        public readonly string $event,
        public readonly string $from,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function fromState(string $event, string $from): static
    {
        return new static($event, $from, sprintf('Event "%s" cannot be handled in state "%s".', $event, $from));
    }
}
