<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

class TransitionNotAllowedException extends LogicException implements StateMachineException
{
    /** @var string @readonly */
    public $event;

    /** @var string @readonly */
    public $from;

    final public function __construct(string $event, string $from, string $message)
    {
        parent::__construct($message);

        $this->event = $event;
        $this->from = $from;
    }

    /**
     * @return static
     */
    public static function fromState(string $event, string $from)
    {
        return new static($event, $from, sprintf('Event "%s" cannot be handled in state "%s".', $event, $from));
    }
}
