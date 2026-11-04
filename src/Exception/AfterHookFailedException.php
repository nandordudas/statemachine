<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use Dudas\StateMachine\TransitionContext;
use RuntimeException;
use Throwable;

/**
 * An `after` or `onEnter` hook threw. The transition HAS happened: the new state is stored
 * and is not rolled back. The original error is available as {@see getPrevious()}.
 */
final class AfterHookFailedException extends RuntimeException implements StateMachineException
{
    public function __construct(
        public readonly TransitionContext $context,
        Throwable $previous,
    ) {
        parent::__construct(
            sprintf(
                'A hook failed after "%s" moved the machine from "%s" to "%s": %s',
                $context->event,
                $context->from,
                $context->to,
                $previous->getMessage(),
            ),
            0,
            $previous,
        );
    }
}
