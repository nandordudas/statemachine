<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

/**
 * A store that can change state conditionally, e.g. `UPDATE ... SET status = :new WHERE status = :expected`.
 * The machine uses it, when available, to refuse a write that would race with another writer.
 */
interface AtomicStateStore extends StateStore
{
    /**
     * @return bool false when the stored state was not `$expected`; nothing is written then
     */
    public function compareAndSet(string $expected, string $state): bool;
}
