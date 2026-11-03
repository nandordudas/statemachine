<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Dudas\StateMachine\Contracts\AtomicStateStore;

final class InMemoryStore implements AtomicStateStore
{
    public function __construct(private string $state) {}

    public function get(): string
    {
        return $this->state;
    }

    public function set(string $state): void
    {
        $this->state = $state;
    }

    public function compareAndSet(string $expected, string $state): bool
    {
        if ($this->state !== $expected) {
            return false;
        }

        $this->state = $state;

        return true;
    }
}
