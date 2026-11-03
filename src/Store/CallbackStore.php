<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Closure;
use Dudas\StateMachine\Exception\UnknownStateException;
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
     * @param Closure(): (string|\BackedEnum|null) $reader
     * @param Closure(string): mixed $writer
     */
    public function __construct(private Closure $reader, private Closure $writer) {}

    public function get(): string
    {
        $state = ($this->reader)();

        return $state === null ? throw UnknownStateException::missing() : Name::of($state);
    }

    public function set(string $state): void
    {
        ($this->writer)($state);
    }
}
