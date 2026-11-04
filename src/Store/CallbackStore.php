<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Closure;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Name;

/**
 * Binds the machine to state that lives elsewhere, e.g. a property of an entity:
 *
 *     new CallbackStore(function () use ($order) { return $order->status; }, function (string $s) use ($order) { $order->status = $s; });
 */
final class CallbackStore implements StateStore
{
    /** @var Closure */
    private $reader;

    /** @var Closure */
    private $writer;

    /**
     * @param Closure(): (string|null) $reader
     * @param Closure(string): mixed $writer
     */
    public function __construct(Closure $reader, Closure $writer)
    {
        $this->reader = $reader;
        $this->writer = $writer;
    }

    public function get(): string
    {
        $state = ($this->reader)();

        if ($state === null) {
            throw UnknownStateException::missing();
        }

        return Name::of($state);
    }

    public function set(string $state): void
    {
        ($this->writer)($state);
    }
}
