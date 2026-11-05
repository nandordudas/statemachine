<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use RuntimeException;

/**
 * Hooks kept queueing events past the machine's limit, most likely two `onEnter` hooks bouncing between
 * states forever. The transitions applied so far stay applied; the remaining queued events are dropped.
 */
final class ChainLimitExceededException extends RuntimeException implements TransitionApplied
{
    public static function after(int $limit, string $event): self
    {
        return new self(sprintf(
            'More than %d events were chained from hooks (next one: "%s"); is there a cycle of onEnter/after hooks?',
            $limit,
            $event,
        ));
    }
}
