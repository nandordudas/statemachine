<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Dudas\StateMachine\Contracts\StateStore;

final class InMemoryStore implements StateStore
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
}
