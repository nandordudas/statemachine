<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use BackedEnum;
use Closure;
use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Exception\UnknownStateException;
use Dudas\StateMachine\Name;

/**
 * Like {@see CallbackStore}, with a conditional writer for state shared between writers:
 *
 *     new AtomicCallbackStore(
 *         fn () => $order->status,
 *         fn (string $expected, string $new): bool => $repo->updateStatus($order->id, $expected, $new) === 1,
 *         afterWrite: fn (string $new) => $order->status = $new,   // keep the entity in step with the row
 *     );
 *
 * When the reader cannot see a successful write yet (it still returns the state the write replaced, e.g. an
 * entity property nobody updated), the store reports the state it wrote, so the machine does not get stuck on
 * the old one. The next compareAndSet still checks the real stored value, so this never hides a race.
 * Prefer `afterWrite` (or a reader that re-reads the row): it makes the cache unnecessary. `afterWrite` must not
 * throw: the row has already changed by then.
 */
final class AtomicCallbackStore implements AtomicStateStore
{
    /** @var array{string, string}|null [state the last write replaced, state it wrote] */
    private ?array $written = null;

    /**
     * @param Closure(): (string|BackedEnum|null) $reader
     * @param Closure(string, string): bool $compareAndSet receives (expected, new); returns whether it wrote
     * @param (Closure(string): mixed)|null $afterWrite runs after a successful write, e.g. to update the entity
     */
    public function __construct(
        private readonly Closure $reader,
        private readonly Closure $compareAndSet,
        private readonly ?Closure $afterWrite = null,
    ) {}

    public function get(): string
    {
        $state = ($this->reader)();

        if ($state === null) {
            throw UnknownStateException::missing();
        }

        $state = Name::of($state);

        if ($this->written !== null && $state === $this->written[0]) {
            return $this->written[1]; // the reader has not caught up with our own write
        }

        $this->written = null;

        return $state;
    }

    /**
     * An unconditional write is not possible through a compare-and-set callback: this writes only if
     * nobody changed the state since it was read here, and throws otherwise.
     */
    public function set(string $state): void
    {
        $expected = $this->get();

        if (!$this->compareAndSet($expected, $state)) {
            throw ConcurrentTransitionException::lostRace($expected, $state);
        }
    }

    public function compareAndSet(string $expected, string $state): bool
    {
        if (!($this->compareAndSet)($expected, $state)) {
            // Somebody else wrote: whatever we remembered about our own write is out of date.
            $this->written = null;

            return false;
        }

        // Remember the write before afterWrite runs: if that throws, the row has moved all the same.
        $this->written = $expected === $state ? null : [$expected, $state];

        if ($this->afterWrite !== null) {
            ($this->afterWrite)($state);
        }

        return true;
    }
}
