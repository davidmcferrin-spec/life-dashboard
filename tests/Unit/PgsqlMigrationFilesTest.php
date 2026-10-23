<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Guards the hand-written PostgreSQL baseline against drift from the legacy
 * SQLite migrations it replaces.
 */
final class PgsqlMigrationFilesTest extends TestCase
{
    public function testFilesAreNumberedSequentiallyFromOne(): void
    {
        $names = array_keys(Schema::pgsqlMigrationFiles());
        self::assertNotEmpty($names);
        $numbers = array_map(static fn (string $name): int => (int) substr($name, 0, 4), $names);
        self::assertSame($numbers, array_values(array_unique($numbers)), 'duplicate migration number');
        self::assertSame(1, $numbers[0] ?? 0);
        // A claimed number can land before the ones between it and main. An unused claim stays spent.
    }

    public function testInvalidFileNameIsRejected(): void
    {
        $dir = sys_get_temp_dir() . '/lifedash-mig-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/0001_ok.sql', 'SELECT 1;');
        file_put_contents($dir . '/2_bad-name.sql', 'SELECT 1;');

        try {
            $this->expectException(\RuntimeException::class);
            Schema::pgsqlMigrationFiles($dir);
        } finally {
            array_map('unlink', glob($dir . '/*.sql') ?: []);
            rmdir($dir);
        }
    }

    public function testBaselineHasEveryLegacyTableAndColumn(): void
    {
        $legacy   = $this->legacyColumns();
        $baseline = $this->tablesAndColumns((string) file_get_contents(Schema::pgsqlMigrationsDir() . '/0001_baseline.sql'));

        foreach ($legacy as $table => $columns) {
            self::assertArrayHasKey($table, $baseline, "baseline is missing table {$table}");
            foreach ($columns as $col) {
                self::assertContains($col, $baseline[$table], "baseline {$table} is missing column {$col}");
            }
        }

        self::assertSame([], array_values(array_diff(array_keys($baseline), array_keys($legacy))), 'baseline has tables the legacy schema never had');
    }

    public function testBaselineUsesNoSqliteOnlySyntax(): void
    {
        $sql = (string) file_get_contents(Schema::pgsqlMigrationsDir() . '/0001_baseline.sql');
        foreach (['AUTOINCREMENT', 'TINYINT', 'DATETIME', ' REAL'] as $bad) {
            self::assertStringNotContainsStringIgnoringCase($bad . ' ', $this->stripComments($sql), "found {$bad}");
        }
    }

    // ---------------------------------------------------------------------

    /**
     * Final legacy schema: replay CREATE TABLE / ALTER ADD COLUMN / DROP TABLE
     * from Schema::migrations() (SQLite flavour).
     *
     * @return array<string, list<string>>
     */
    private function legacyColumns(): array
    {
        putenv('DB_DRIVER=sqlite');
        try {
            $ref = new \ReflectionMethod(Schema::class, 'migrations');
            $ref->setAccessible(true);
            $schema = (new \ReflectionClass(Schema::class))->newInstanceWithoutConstructor();
            /** @var array<string, string> $migrations */
            $migrations = $ref->invoke($schema);
        } finally {
            putenv('DB_DRIVER');
        }

        $tables = [];
        foreach ($migrations as $sql) {
            if (preg_match('/DROP TABLE IF EXISTS (\w+)/i', $sql, $m)) {
                unset($tables[$m[1]]);
                continue;
            }
            if (preg_match('/ALTER TABLE (\w+) ADD COLUMN (\w+)/i', $sql, $m)) {
                $tables[$m[1]][] = $m[2];
                continue;
            }
            $tables = array_merge($tables, $this->tablesAndColumns($sql));
        }

        return $tables;
    }

    /** @return array<string, list<string>> */
    private function tablesAndColumns(string $sql): array
    {
        $sql = $this->stripComments($sql);
        $out = [];
        if (!preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)? (\w+)\s*\((.*?)\n\s*\)\s*(?:;|$)/is', $sql, $all, PREG_SET_ORDER)) {
            return $out;
        }
        foreach ($all as [, $table, $body]) {
            $cols = [];
            foreach (preg_split('/,\s*\n/', $body) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || preg_match('/^(UNIQUE|FOREIGN|PRIMARY|CHECK|CONSTRAINT)\b/i', $line)) {
                    continue;
                }
                if (preg_match('/^(\w+)\s/', $line, $m)) {
                    $cols[] = $m[1];
                }
            }
            $out[$table] = $cols;
        }

        return $out;
    }

    private function stripComments(string $sql): string
    {
        return (string) preg_replace('/--[^\n]*/', '', $sql);
    }
}
