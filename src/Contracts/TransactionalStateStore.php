<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

use Closure;

/**
 * A store that can run the decision and the write of a transition as one unit, e.g. inside
 * `BEGIN; SELECT ... FOR UPDATE; ...; COMMIT`. The machine reads the state, evaluates guards, runs
 * `before`/`onExit` hooks and writes the new state inside {@see transaction()}; `onEnter`/`after` hooks
 * run once it has returned (after commit).
 *
 * Use it when guards read data besides the state (a balance, a stock level): a compare-and-set on the
 * state alone cannot notice that such data changed between the guard and the write.
 */
interface TransactionalStateStore extends StateStore
{
    /**
     * Runs `$work` atomically and returns its result. When `$work` throws, nothing it wrote may persist
     * and the exception must propagate.
     *
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    public function transaction(Closure $work): mixed;
}
