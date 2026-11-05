<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Store;

use Closure;
use Dudas\StateMachine\Contracts\AtomicStateStore;
use Dudas\StateMachine\Contracts\StateStore;
use Dudas\StateMachine\Contracts\TransactionalStateStore;
use Dudas\StateMachine\Exception\InvalidDefinitionException;

/**
 * Wraps any store so each transition is decided and written under a lock or database transaction:
 *
 *     new LockingStore(
 *         new PropertyStore($order),
 *         fn (Closure $work) => $db->transactional(function () use ($db, $order, $work) {
 *             $db->refreshForUpdate($order);   // SELECT ... FOR UPDATE: fresh state and guard inputs
 *             return $work();
 *         }),
 *     );
 *
 * Writes go through the inner store's compareAndSet when it has one.
 */
final readonly class LockingStore implements AtomicStateStore, TransactionalStateStore
{
    /**
     * @param Closure(Closure(): mixed): mixed $transaction runs the given work atomically and returns its result
     */
    public function __construct(
        private StateStore $inner,
        private Closure $transaction,
    ) {}

    #[\Override]
    public function get(): string
    {
        return $this->inner->get();
    }

    #[\Override]
    public function set(string $state): void
    {
        $this->inner->set($state);
    }

    #[\Override]
    public function compareAndSet(string $expected, string $state): bool
    {
        if ($this->inner instanceof AtomicStateStore) {
            return $this->inner->compareAndSet($expected, $state);
        }

        // Only meaningful under the lock taken by transaction(), which is where the machine calls it.
        if ($this->inner->get() !== $expected) {
            return false;
        }

        $this->inner->set($state);

        return true;
    }

    /**
     * @template T
     * @param Closure(): T $work
     * @return T
     */
    #[\Override]
    public function transaction(Closure $work): mixed
    {
        $outcome = new class {
            public bool $ran = false;

            public mixed $result = null;
        };

        ($this->transaction)(static function () use ($work, $outcome): mixed {
            $outcome->result = $work();
            $outcome->ran = true;

            return $outcome->result;
        });

        if (!$outcome->ran) {
            throw new InvalidDefinitionException('The LockingStore transaction must call its work.');
        }

        /** @var T $result */
        $result = $outcome->result;

        return $result;
    }
}
