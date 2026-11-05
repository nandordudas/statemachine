<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use Dudas\StateMachine\TransitionContext;
use Exception;
use RuntimeException;
use Throwable;

/**
 * An `after` or `onEnter` hook threw. The transition HAS happened: the new state is stored
 * and is not rolled back. Every post-transition hook still ran; the first failure is available as
 * {@see getPrevious()}, all of them in {@see $failures}. Events queued by hooks of this transition are dropped.
 */
final class AfterHookFailedException extends RuntimeException implements TransitionApplied
{
    /** @var list<Exception> every hook failure, in the order the hooks ran */
    public readonly array $failures;

    /**
     * @param list<Exception> $failures defaults to just `$previous`
     */
    public function __construct(
        public readonly TransitionContext $context,
        Throwable $previous,
        array $failures = [],
    ) {
        if ($failures === [] && $previous instanceof Exception) {
            $failures = [$previous];
        }

        $this->failures = $failures;

        parent::__construct(
            sprintf(
                'A hook failed after "%s" moved the machine from "%s" to "%s": %s%s',
                $context->event,
                $context->from,
                $context->to,
                $previous->getMessage(),
                count($this->failures) > 1 ? sprintf(' (and %d more)', count($this->failures) - 1) : '',
            ),
            0,
            $previous,
        );
    }
}
