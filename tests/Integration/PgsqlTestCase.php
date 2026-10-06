<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\DB\Database;
use Dashboard\DB\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need a real PostgreSQL database.
 *
 * Skipped unless LIFEDASH_TEST_PG_DB is set. The database name MUST end in
 * "_test": setUp() drops and recreates its public schema.
 */
abstract class PgsqlTestCase extends TestCase
{
    protected Database $db;

    protected function setUp(): void
    {
        $name = getenv('LIFEDASH_TEST_PG_DB');
        if ($name === false || $name === '') {
            self::markTestSkipped('Set LIFEDASH_TEST_PG_DB (and _HOST/_USER/_PASS) to run PostgreSQL integration tests.');
        }
        if (!str_ends_with($name, '_test')) {
            self::fail("Refusing to use database '{$name}': integration tests wipe it, so its name must end in _test.");
        }

        $env = [
            'DB_DRIVER'   => 'pgsql',
            'DB_HOST'     => getenv('LIFEDASH_TEST_PG_HOST') ?: '127.0.0.1',
            'DB_PORT'     => getenv('LIFEDASH_TEST_PG_PORT') ?: '5432',
            'DB_NAME'     => $name,
            'DB_USER'     => getenv('LIFEDASH_TEST_PG_USER') ?: 'lifedash',
            'DB_PASS'     => getenv('LIFEDASH_TEST_PG_PASS') ?: '',
            'DB_SSLMODE'  => 'disable',
            'APP_TIMEZONE'=> 'America/Chicago',
        ];
        foreach ($env as $k => $v) {
            putenv("{$k}={$v}");
        }

        Database::reset();
        $this->db = Database::getInstance();
        $this->db->pdo()->exec('DROP SCHEMA public CASCADE; CREATE SCHEMA public;');
    }

    protected function tearDown(): void
    {
        Database::reset();
    }

    protected function migrate(): void
    {
        (new Schema($this->db))->migrate(quiet: true);
    }
}
