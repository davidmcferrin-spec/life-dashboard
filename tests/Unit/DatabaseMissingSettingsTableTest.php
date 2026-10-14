<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Database;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DatabaseMissingSettingsTableTest extends TestCase
{
    public function testPostgresMissingSettingsTableIsExpected(): void
    {
        $e = self::pdo(
            'SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "system_settings" does not exist',
            '42P01',
        );

        self::assertTrue(Database::isMissingSettingsTable($e));
    }

    public function testSqliteMissingSettingsTableIsExpected(): void
    {
        $e = self::pdo(
            'SQLSTATE[HY000]: General error: 1 no such table: system_settings',
            'HY000',
        );

        self::assertTrue(Database::isMissingSettingsTable($e));
    }

    public function testADifferentMissingTableStillCountsAsAFailure(): void
    {
        $e = self::pdo(
            'SQLSTATE[42P01]: Undefined table: 7 ERROR:  relation "users" does not exist',
            '42P01',
        );

        self::assertFalse(Database::isMissingSettingsTable($e));
    }

    public function testOtherSetupFailuresStillCount(): void
    {
        self::assertFalse(Database::isMissingSettingsTable(
            self::pdo('SQLSTATE[28P01]: password authentication failed', '28P01'),
        ));
        self::assertFalse(Database::isMissingSettingsTable(new RuntimeException('decrypt failed')));
    }

    private static function pdo(string $message, string $sqlstate): PDOException
    {
        return new class($message, $sqlstate) extends PDOException {
            public function __construct(string $message, string $sqlstate)
            {
                parent::__construct($message);
                $this->code = $sqlstate;
            }
        };
    }
}
