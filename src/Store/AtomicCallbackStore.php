<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Closure;
use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Name;

/**
 * Like {@see CallbackStore}, with a conditional writer for state shared between writers.
 */
final class AtomicCallbackStore implements AtomicStateStore
{
    /** @var Closure */
    private $reader;

    /** @var Closure */
    private $compareAndSet;

    /**
     * @param Closure(): (string|null) $reader
     * @param Closure(string, string): bool $compareAndSet receives (expected, new); returns whether it wrote
     */
    public function __construct(Closure $reader, Closure $compareAndSet)
    {
        $this->reader = $reader;
        $this->compareAndSet = $compareAndSet;
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
        $expected = $this->get();

        if (!$this->compareAndSet($expected, $state)) {
            throw ConcurrentTransitionException::lostRace($expected, $state);
        }
    }

    public function compareAndSet(string $expected, string $state): bool
    {
        return (bool) ($this->compareAndSet)($expected, $state);
    }
}
