<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Fixtures;

use Dudas\StateMachine\Machine;
use LogicException;

/** Lets hooks declared before the machine exists reach it once it does. */
final class MachineRef
{
    public ?Machine $machine = null;

    public function get(): Machine
    {
        return $this->machine ?? throw new LogicException('The machine is not set yet.');
    }
}
