<?php

declare(strict_types=1);

namespace Dashboard\DB;

use Dashboard\Config;
use PDO;
use PDOException;
use PDOStatement;

/**
 * Database — thin PDO wrapper.
 *
 * Drivers:
 *   pgsql  — PostgreSQL (production target for life-dashboard)
 *   sqlite — legacy personal-dashboard driver; kept as the import source for
 *            scripts/migrate-sqlite-to-pgsql.php and for offline dev
 *   mysql  — legacy, untested since the PostgreSQL move
 *
 * Usage:
 *   $db = Database::getInstance();
 *   $rows = $db->fetchAll('SELECT * FROM users WHERE active = ?', [1]);
 */
class Database
{
    public const DRIVERS = ['pgsql', 'sqlite', 'mysql'];

    private static ?Database $instance = null;
    private PDO $pdo;
    private string $driver;
    private int $savepointSeq = 0;

    private function __construct()
    {
        $driver = Config::get('DB_DRIVER', 'pgsql');
        $this->driver = $driver;

        $this->pdo = match ($driver) {
            'pgsql'  => $this->connectPgSQL(),
            'sqlite' => $this->connectSQLite(),
            'mysql'  => $this->connectMySQL(),
            default  => throw new \RuntimeException(
                "Unsupported DB_DRIVER: {$driver} (expected one of: " . implode(', ', self::DRIVERS) . ')'
            ),
        };

        // Common PDO settings
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

        // SQLite-specific pragmas for reliability and performance
        if ($driver === 'sqlite') {
            $this->pdo->exec('PRAGMA journal_mode = WAL');
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            $this->pdo->exec('PRAGMA synchronous = NORMAL');
        }
    }

    /**
     * PostgreSQL connection.
     *
     * The session time zone is forced to UTC so CURRENT_TIMESTAMP written into
     * TIMESTAMP (without time zone) columns matches legacy SQLite semantics.
     * DB_PGSQL_SOCKET_DIR lets the app use the local Unix socket (peer/scram)
     * instead of TCP; leave DB_HOST empty in that case.
     */
    private function connectPgSQL(): PDO
    {
        $socketDir = Config::maybe('DB_PGSQL_SOCKET_DIR');
        $host      = $socketDir ?? Config::get('DB_HOST', '127.0.0.1');
        $port      = Config::get('DB_PORT', '5432');
        $name      = Config::get('DB_NAME', 'lifedash');
        $user      = Config::get('DB_USER', 'lifedash');
        $pass      = Config::maybe('DB_PASS');
        $sslmode   = Config::get('DB_SSLMODE', 'prefer');
        $appName   = Config::get('DB_APPLICATION_NAME', 'lifedash-php');

        $dsn = sprintf(
            "pgsql:host=%s;port=%s;dbname=%s;sslmode=%s;application_name=%s;options='--timezone=UTC'",
            $host,
            $port,
            $name,
            $sslmode,
            $appName,
        );

        try {
            return new PDO($dsn, $user, $pass, [
                PDO::ATTR_TIMEOUT => Config::int('DB_CONNECT_TIMEOUT', 5),
            ]);
        } catch (PDOException $e) {
            // Never leak the DSN/password into logs or the browser.
            error_log('[Dashboard][DB] PostgreSQL connection failed: ' . $e->getMessage());
            throw new \RuntimeException('Database connection failed (pgsql). Check DB_* settings and that PostgreSQL is running.', 0, $e);
        }
    }

    private function connectSQLite(): PDO
    {
        $path = Config::get('DB_SQLITE_PATH', '../storage/dashboard.db');

        // Resolve relative path from project root
        $resolvedPath = str_starts_with($path, '/')
            ? $path
            : ROOT_DIR . '/' . ltrim($path, './');

        $dir = dirname($resolvedPath);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new \RuntimeException("Cannot create SQLite directory: {$dir}");
        }

        return new PDO("sqlite:{$resolvedPath}");
    }

    private function connectMySQL(): PDO
    {
        $host = Config::get('DB_HOST', 'localhost');
        $port = Config::get('DB_PORT', '3306');
        $name = Config::get('DB_NAME');
        $user = Config::get('DB_USER');
        $pass = Config::get('DB_PASS');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        return new PDO($dsn, $user, $pass, [
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
        ]);
    }

    /** Active driver name: pgsql | sqlite | mysql. */
    public function driver(): string
    {
        return $this->driver;
    }

    public function isPgsql(): bool
    {
        return $this->driver === 'pgsql';
    }

    /**
     * Drop the cached singleton (tests and long-running CLI workers that need
     * to reconnect after a PostgreSQL restart).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
            try {
                \Dashboard\Settings\SetupConfig::apply(self::$instance);
            } catch (\Throwable $e) {
                error_log('[Dashboard][setup] ' . $e->getMessage());
            }
        }
        return self::$instance;
    }

    // -------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------

    /**
     * Execute a statement and return the PDOStatement.
     *
     * @param array<int|string, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(self::normalizeParams($params));
        return $stmt;
    }

    /**
     * PDO::execute() binds every value as a string, so PHP false becomes ''.
     * SQLite silently stored '' in integer columns; PostgreSQL rejects it
     * ("invalid input syntax for type smallint"). Map booleans to 0/1 so the
     * legacy call sites keep working on every driver.
     *
     * @param array<int|string, mixed> $params
     * @return array<int|string, mixed>
     */
    public static function normalizeParams(array $params): array
    {
        foreach ($params as $key => $value) {
            if (is_bool($value)) {
                $params[$key] = $value ? 1 : 0;
            }
        }

        return $params;
    }

    /**
     * Fetch all rows as associative arrays.
     *
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch a single row or null.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    /**
     * Fetch a single scalar value or null.
     *
     * @param array<int|string, mixed> $params
     */
    public function fetchScalar(string $sql, array $params = []): mixed
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /**
     * INSERT / UPDATE / DELETE — returns affected row count.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * Last inserted row ID.
     */
    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * Wrap multiple operations in a transaction.
     * Rolls back automatically on exception.
     */
    public function transaction(callable $callback): mixed
    {
        // Nested call: use a savepoint so an inner failure rolls back only the inner work
        // (services compose — e.g. seeding the grid creates widgets transactionally).
        if ($this->pdo->inTransaction()) {
            $sp = 'sp_' . (++$this->savepointSeq);
            $this->pdo->exec("SAVEPOINT {$sp}");
            try {
                $result = $callback($this);
                $this->pdo->exec("RELEASE SAVEPOINT {$sp}");
                return $result;
            } catch (\Throwable $e) {
                $this->pdo->exec("ROLLBACK TO SAVEPOINT {$sp}");
                throw $e;
            }
        }

        $this->pdo->beginTransaction();
        try {
            $result = $callback($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Raw PDO access for edge cases.
     */
    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Whether a table exists in the current database.
     */
    public function tableExists(string $table): bool
    {
        $driver = $this->driver;

        if ($driver === 'pgsql') {
            return $this->fetchOne(
                "SELECT 1 FROM information_schema.tables
                 WHERE table_schema = current_schema() AND table_name = ? LIMIT 1",
                [$table]
            ) !== null;
        }

        if ($driver === 'sqlite') {
            // sql-lint:ignore — SQLite catalog
            return $this->fetchOne(
                "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1",
                [$table]
            ) !== null;
        }

        // sql-lint:ignore — MySQL catalog
        return $this->fetchOne(
            'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1',
            [$table]
        ) !== null;
    }
}
