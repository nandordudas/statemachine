<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Guards;

use Closure;
use Dudas\StateMachine\Blocker;
use Dudas\StateMachine\ClosureGuard;
use Dudas\StateMachine\Contracts\ExplainingGuard;
use Dudas\StateMachine\Contracts\Guard;
use Dudas\StateMachine\TransitionContext;

/**
 * Gives any guard a reason to show when it refuses:
 *
 *     because(payloadIs('role', 'approver'), 'Only approvers can approve.', code: 'not_approver')
 *
 * The message may be a closure of the context, e.g. to mention an amount from the payload.
 */
final readonly class Because implements ExplainingGuard
{
    private Guard $guard;

    /**
     * @param Guard|callable(TransitionContext): bool $guard
     * @param string|Closure(TransitionContext): string $message
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        Guard|callable $guard,
        private string|Closure $message,
        private string $code = 'rejected',
        private array $parameters = [],
    ) {
        $this->guard = ClosureGuard::wrap($guard);
    }

    public function allows(TransitionContext $context): bool
    {
        return $this->guard->allows($context);
    }

    public function blockers(TransitionContext $context): array
    {
        if ($this->guard->allows($context)) {
            return [];
        }

        $message = is_string($this->message) ? $this->message : ($this->message)($context);

        return [new Blocker($this->code, $message, $this->parameters)];
    }
}
