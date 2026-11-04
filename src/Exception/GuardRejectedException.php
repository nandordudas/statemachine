<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Exception;

use Dudas\StateMachine\Blocker;

/**
 * The event is valid for the current state, but every matching transition's guard said no.
 * {@see blockers()} says why.
 */
final class GuardRejectedException extends TransitionNotAllowedException
{
    /** @var list<Blocker> */
    private array $blockers = [];

    /**
     * @param list<Blocker> $blockers
     */
    public static function forEvent(string $event, string $from, array $blockers = []): self
    {
        $message = sprintf('Guard rejected event "%s" in state "%s".', $event, $from);

        if ($blockers !== []) {
            $message .=
                ' '
                . implode(
                    ' ',
                    array_unique(array_map(static fn(Blocker $blocker): string => $blocker->message, $blockers)),
                );
        }

        $exception = new self($event, $from, $message);
        $exception->blockers = $blockers;

        return $exception;
    }

    /**
     * @return list<Blocker> why the guards refused, for every candidate transition
     */
    public function blockers(): array
    {
        return $this->blockers;
    }
}
