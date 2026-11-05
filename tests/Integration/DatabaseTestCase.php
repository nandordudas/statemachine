<?php

declare(strict_types=1);

namespace Dudas\StateMachine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Runs against a real database: SQLite by default (a temporary file, so two connections see one database), or
 * whatever `DB_DSN` / `DB_USER` / `DB_PASS` point at (CI uses MySQL). Skipped when the PDO driver is missing.
 */
abstract class DatabaseTestCase extends TestCase
{
    private string $sqliteFile = '';

    protected function setUp(): void
    {
        $dsn = $this->dsn();
        $driver = strstr($dsn, ':', true);

        if (!in_array($driver, PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped(sprintf('The PDO driver "%s" is not available.', (string) $driver));
        }

        $pdo = $this->connect();
        $pdo->exec('DROP TABLE IF EXISTS orders');
        $pdo->exec(
            'CREATE TABLE orders (id INTEGER PRIMARY KEY, status VARCHAR(32) NOT NULL, balance INTEGER NOT NULL DEFAULT 0)',
        );
        $pdo->exec("INSERT INTO orders (id, status, balance) VALUES (1, 'draft', 0)");
    }

    protected function tearDown(): void
    {
        if ($this->sqliteFile !== '' && is_file($this->sqliteFile)) {
            unlink($this->sqliteFile);
        }
    }

    protected function connect(): PDO
    {
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');

        $pdo = new PDO($this->dsn(), $user === false ? null : $user, $pass === false ? null : $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        if ($this->isSqlite()) {
            $pdo->exec('PRAGMA busy_timeout = 2000');
        }

        return $pdo;
    }

    protected function isSqlite(): bool
    {
        return str_starts_with($this->dsn(), 'sqlite:');
    }

    protected function rowStatus(PDO $pdo, int $id = 1): string
    {
        $status = $this->scalar($pdo, 'SELECT status FROM orders WHERE id = ' . $id);

        return is_string($status) ? $status : '';
    }

    protected function scalar(PDO $pdo, string $sql): mixed
    {
        $statement = $pdo->query($sql);
        self::assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    private function dsn(): string
    {
        $dsn = getenv('DB_DSN');

        if ($dsn !== false && $dsn !== '') {
            return $dsn;
        }

        if ($this->sqliteFile === '') {
            $this->sqliteFile =
                sys_get_temp_dir() . '/state-machine-' . getmypid() . '-' . spl_object_id($this) . '.sqlite';
        }

        return 'sqlite:' . $this->sqliteFile;
    }
}
