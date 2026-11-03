<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Closure;
use Dudas\StateMachine\Name;
use Dudas\StateMachine\Contracts\StateStore;

/**
 * Binds the machine to state that lives elsewhere, e.g. a property of an entity:
 *
 *     new CallbackStore(fn () => $order->status, fn (string $s) => $order->status = $s);
 */
final readonly class CallbackStore implements StateStore
{
    /**
     * @param Closure(): (string|\BackedEnum) $reader
     * @param Closure(string): mixed $writer
     */
    public function __construct(private Closure $reader, private Closure $writer) {}

    public function get(): string
    {
        return Name::of(($this->reader)());
    }

    public function set(string $state): void
    {
        ($this->writer)($state);
    }
}
