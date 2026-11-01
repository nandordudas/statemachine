<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Contracts;

/**
 * Where the current state lives: in memory, on a domain object, in a row.
 */
interface StateStore
{
    public function get(): string;

    public function set(string $state): void;
}
