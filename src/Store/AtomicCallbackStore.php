<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use BackedEnum;
use Closure;
use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Name;

/**
 * Like {@see CallbackStore}, with a conditional writer for state shared between writers:
 *
 *     new AtomicCallbackStore(
 *         fn () => $order->status,
 *         fn (string $expected, string $new): bool => $repo->updateStatus($order->id, $expected, $new) === 1,
 *     );
 */
final readonly class AtomicCallbackStore implements AtomicStateStore
{
    /**
     * @param Closure(): (string|BackedEnum|null) $reader
     * @param Closure(string, string): bool $compareAndSet receives (expected, new); returns whether it wrote
     */
    public function __construct(private Closure $reader, private Closure $compareAndSet) {}

    public function get(): string
    {
        $state = ($this->reader)();

        return $state === null ? throw UnknownStateException::missing() : Name::of($state);
    }

    public function set(string $state): void
    {
        $this->compareAndSet($this->get(), $state);
    }

    public function compareAndSet(string $expected, string $state): bool
    {
        return ($this->compareAndSet)($expected, $state);
    }
}
