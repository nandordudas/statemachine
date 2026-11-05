<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

/**
 * Implemented by exceptions thrown AFTER the transition was applied: the new state is stored and stays.
 * Retrying the same event would be refused, so a queue consumer should acknowledge the message (and handle
 * the failure), not retry it.
 */
interface TransitionApplied extends StateMachineException {}
