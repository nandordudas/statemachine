<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use BackedEnum;
use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Name;

final class InMemoryStore implements AtomicStateStore
{
    private string $state;

    public function __construct(string|BackedEnum $state)
    {
        $this->state = Name::of($state);
    }

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
