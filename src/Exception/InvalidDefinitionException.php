<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use LogicException;

final class InvalidDefinitionException extends LogicException implements StateMachineException {}
