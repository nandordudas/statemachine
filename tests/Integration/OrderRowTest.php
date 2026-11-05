<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Integration;

use Closure;
use Dudas\StateMachine\Definition;
use Dudas\StateMachine\Exception\ConcurrentTransitionException;
use Dudas\StateMachine\Machine;
use Dudas\StateMachine\Store\AtomicCallbackStore;
use Dudas\StateMachine\Store\LockingStore;
use Dudas\StateMachine\Store\PropertyStore;
use Dudas\StateMachine\Transition;
use Dudas\StateMachine\TransitionContext;
use PDO;
use RuntimeException;

/**
 * The concurrency claims of the README, proven against a real database instead of simulated rows.
 */
final class OrderRowTest extends DatabaseTestCase
{
    private function definition(): Definition
    {
        return Definition::builder()
            ->initial('draft')
            ->states('draft', 'review', 'cancelled', 'paid')
            ->transition(
                Transition::on('SUBMIT', 'draft', 'review'),
                Transition::on('CANCEL', ['draft', 'review'], 'cancelled'),
                Transition::on('TOUCH', 'review', 'review'),
                Transition::on('PAY', 'review', 'paid')->withGuard(
                    static fn(TransitionContext $c): bool => ($c->payload['balance'] ?? 0) >= 10,
                ),
            )
            ->build();
    }

    /**
     * A machine over a loaded copy of the row (the "entity") whose writes are `UPDATE ... WHERE status = :expected`.
     */
    private function machineOver(PDO $pdo, OrderEntity $entity): Machine
    {
        $update = $pdo->prepare('UPDATE orders SET status = :new WHERE id = 1 AND status = :expected');

        return new Machine(
            $this->definition(),
            new AtomicCallbackStore(
                static fn(): string => $entity->status,
                static function (string $expected, string $new) use ($update): bool {
                    $update->execute(['new' => $new, 'expected' => $expected]);

                    return $update->rowCount() === 1;
                },
                static function (string $new) use ($entity): void {
                    $entity->status = $new;
                },
            ),
        );
    }

    private function load(PDO $pdo): OrderEntity
    {
        $entity = new OrderEntity();
        $entity->status = $this->rowStatus($pdo);

        return $entity;
    }

    public function testTwoProcessesThatLoadedTheSameRowCannotBothTransition(): void
    {
        $a = $this->connect();
        $b = $this->connect();
        $first = $this->machineOver($a, $this->load($a));
        $second = $this->machineOver($b, $this->load($b)); // both saw "draft"

        $first->send('SUBMIT');

        try {
            $second->send('CANCEL'); // valid for the stale copy, not for the row any more
            self::fail('Expected the stale writer to lose the race.');
        } catch (ConcurrentTransitionException $e) {
            self::assertTrue($e->retryable);
        }

        self::assertSame(
            'review',
            $this->rowStatus($a),
            'review -> cancelled must not be persisted by the stale writer',
        );
    }

    public function testASelfLoopIsNotMistakenForALostRace(): void
    {
        $pdo = $this->connect();
        $pdo->exec("UPDATE orders SET status = 'review' WHERE id = 1");
        $machine = $this->machineOver($pdo, $this->load($pdo));

        // MySQL reports 0 affected rows for UPDATE ... SET status = 'review' WHERE status = 'review'.
        $machine->send('TOUCH');

        self::assertSame('review', $this->rowStatus($pdo));
    }

    public function testGuardsReadDataUnderTheRowLockAndTheWriteCommitsWithIt(): void
    {
        $pdo = $this->connect();
        $pdo->exec("UPDATE orders SET status = 'review', balance = 25 WHERE id = 1");
        $entity = $this->load($pdo);
        $lock = $this->isSqlite() ? '' : ' FOR UPDATE';

        $store = new LockingStore(new PropertyStore($entity), static function (Closure $work) use (
            $pdo,
            $entity,
            $lock,
        ): mixed {
            $pdo->beginTransaction();

            try {
                $locked = $pdo->query('SELECT status FROM orders WHERE id = 1' . $lock);
                $status = $locked === false ? null : $locked->fetchColumn();
                $entity->status = is_string($status) ? $status : '';
                $result = $work();
                $pdo->exec("UPDATE orders SET status = '" . $entity->status . "' WHERE id = 1");
                $pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                $pdo->rollBack();

                throw $e;
            }
        });
        $machine = new Machine($this->definition(), $store);

        $machine->send('PAY', ['balance' => 25]);

        self::assertSame('paid', $this->rowStatus($this->connect()));
    }

    public function testAFailingBeforeHookRollsTheRowBack(): void
    {
        $pdo = $this->connect();
        $pdo->exec("UPDATE orders SET status = 'review' WHERE id = 1");
        $entity = $this->load($pdo);
        $definition = Definition::builder()
            ->initial('review')
            ->states('review', 'paid')
            ->transition(Transition::on('PAY', 'review', 'paid')->withBefore(static function (): void {
                throw new RuntimeException('payment gateway down');
            }))
            ->build();
        $store = new LockingStore(new PropertyStore($entity), static function (Closure $work) use (
            $pdo,
            $entity,
        ): mixed {
            $pdo->beginTransaction();

            try {
                $result = $work();
                $pdo->exec("UPDATE orders SET status = '" . $entity->status . "' WHERE id = 1");
                $pdo->commit();

                return $result;
            } catch (\Throwable $e) {
                $pdo->rollBack();

                throw $e;
            }
        });

        try {
            new Machine($definition, $store)->send('PAY');
            self::fail('Expected the before hook to fail.');
        } catch (RuntimeException) {
        }

        self::assertSame('review', $this->rowStatus($this->connect()));
    }
}
