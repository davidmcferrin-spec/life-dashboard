<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\DB\Schema;
use Dashboard\DB\SqlStatementExtractor;
use Dashboard\Support\SqlTime;

final class PgsqlSchemaTest extends PgsqlTestCase
{
    public function testMigrateIsIdempotentAndRecordsEachFile(): void
    {
        $this->migrate();
        $this->migrate();

        $ran = array_column($this->db->fetchAll('SELECT name FROM migrations ORDER BY name'), 'name');
        self::assertSame(array_keys(Schema::pgsqlMigrationFiles()), $ran);
        self::assertTrue($this->db->tableExists('user_todo_items'));
        self::assertFalse($this->db->tableExists('flights'), 'legacy table dropped in 040 must not exist');
    }

    public function testSessionTimezoneIsUtcAndDefaultsAreUtc(): void
    {
        $this->migrate();
        self::assertSame('UTC', $this->db->fetchScalar('SHOW TimeZone'));

        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('u', 'u@x', 'h')");
        $created = (string) $this->db->fetchScalar('SELECT created_at FROM users');
        $drift   = abs(strtotime($created . ' UTC') - time());
        self::assertLessThan(120, $drift, "created_at {$created} is not UTC now");
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $created, 'TIMESTAMP(0) keeps the legacy format');
    }

    public function testLastInsertIdAndBooleanParams(): void
    {
        $this->migrate();
        $this->db->execute('INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)', ['a', 'a@x', 'h', false]);
        $id = (int) $this->db->lastInsertId();
        self::assertGreaterThan(0, $id);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT is_admin FROM users WHERE id = ?', [$id]));
    }

    public function testFlightPollQueryUsesPortableCutoffs(): void
    {
        $this->migrate();
        $this->db->execute("INSERT INTO users (id, username, email, password_hash) OVERRIDING SYSTEM VALUE VALUES (1, 'u', 'u@x', 'h')");

        $soon  = SqlTime::localHoursFromNow(2, 'America/Chicago');
        $later = SqlTime::localHoursFromNow(48, 'America/Chicago');
        $this->db->execute('INSERT INTO user_flight_watches (user_id, ident, departure_at) VALUES (1, ?, ?)', ['SOON1', $soon]);
        $this->db->execute('INSERT INTO user_flight_watches (user_id, ident, departure_at) VALUES (1, ?, ?)', ['LATE1', $later]);
        $this->db->execute(
            'INSERT INTO user_flight_watches (user_id, ident, departure_at, last_checked_at) VALUES (1, ?, ?, ?)',
            ['FRESH', $soon, SqlTime::utcNow()]
        );

        $due = (new \Dashboard\Flight\FlightWatchRepository($this->db))->listDueForPoll(30);
        self::assertSame(['SOON1'], array_column($due, 'ident'));
    }

    public function testEveryStaticQueryInTheCodebasePreparesOnPostgres(): void
    {
        $this->migrate();

        $failures = [];
        $checked  = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_DIR . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach (array_merge(iterator_to_array($it, false), glob(ROOT_DIR . '/cron/*.php') ?: [], glob(ROOT_DIR . '/scripts/*.php') ?: []) as $file) {
            $path = is_string($file) ? $file : $file->getPathname();
            if (!str_ends_with($path, '.php')) {
                continue;
            }
            foreach (SqlStatementExtractor::extract((string) file_get_contents($path)) as $stmt) {
                if ($stmt['dynamic']) {
                    continue; // covered by the smoke test at runtime
                }
                $checked++;
                $name = 'it_' . $checked;
                try {
                    $this->db->pdo()->exec("PREPARE {$name} AS " . SqlStatementExtractor::toPgPlaceholders($stmt['sql']));
                    $this->db->pdo()->exec("DEALLOCATE {$name}");
                } catch (\PDOException $e) {
                    $failures[] = basename($path) . ':' . $stmt['line'] . ' — ' . $e->getMessage();
                }
            }
        }

        self::assertGreaterThan(200, $checked, 'extractor found suspiciously few statements');
        self::assertSame([], $failures);
    }
}
