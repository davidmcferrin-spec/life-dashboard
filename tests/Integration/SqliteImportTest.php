<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\DB\SqliteToPgsqlImporter;
use PDO;

final class SqliteImportTest extends PgsqlTestCase
{
    private string $sqliteFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->sqliteFile = tempnam(sys_get_temp_dir(), 'lifedash-src-') ?: '';
    }

    protected function tearDown(): void
    {
        if ($this->sqliteFile !== '' && is_file($this->sqliteFile)) {
            unlink($this->sqliteFile);
        }
        parent::tearDown();
    }

    /** Minimal legacy-shaped source with the quirks seen in real SQLite data. */
    private function source(bool $withOrphan = false): PDO
    {
        $s = new PDO('sqlite:' . $this->sqliteFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $s->exec("
            CREATE TABLE users (id INTEGER PRIMARY KEY, username VARCHAR(64), email VARCHAR(255),
                password_hash VARCHAR(255), is_admin TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP, last_login_at DATETIME, legacy_col TEXT);
            CREATE TABLE bookmark_folders (id INTEGER PRIMARY KEY, user_id INTEGER, name VARCHAR(128),
                icon VARCHAR(64), sort_order INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE bookmarks (id INTEGER PRIMARY KEY, user_id INTEGER, folder_id INTEGER, title VARCHAR(255),
                url TEXT, favicon_url TEXT, sort_order INTEGER DEFAULT 0, created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
            CREATE TABLE notes (id INTEGER PRIMARY KEY, body TEXT);
            CREATE TABLE migrations (id INTEGER PRIMARY KEY, name TEXT, ran_at DATETIME);

            INSERT INTO users (id, username, email, password_hash, is_admin, last_login_at, legacy_col)
                VALUES (5, 'david', 'd@x', 'h', '', '2026-09-01T12:00:00Z', 'x');
            INSERT INTO users (id, username, email, password_hash, is_admin) VALUES (9, 'ann', 'a@x', 'h', 1);
            INSERT INTO bookmark_folders (id, user_id, name) VALUES (3, 5, 'Work');
            INSERT INTO bookmarks (id, user_id, folder_id, title, url) VALUES (40, 5, 3, 'Ars', 'https://arstechnica.com');
        ");
        if ($withOrphan) {
            $s->exec("INSERT INTO bookmarks (id, user_id, folder_id, title, url) VALUES (41, 77, NULL, 'Orphan', 'https://x')");
        }

        return $s;
    }

    public function testImportCopiesRowsKeepsIdsAndResetsSequences(): void
    {
        $importer = new SqliteToPgsqlImporter($this->source(), $this->db->pdo());
        $plan     = $importer->preflight();

        self::assertSame([], $plan['errors']);
        self::assertContains('users: source columns not in target, ignored: legacy_col', $plan['warnings']);
        self::assertContains('source table notes has no target table — not imported', $plan['warnings']);

        $counts = $importer->import($plan);
        self::assertSame(2, $counts['users']);
        self::assertSame(1, $counts['bookmarks']);

        $david = $this->db->fetchOne('SELECT * FROM users WHERE id = 5');
        self::assertSame(0, (int) $david['is_admin'], "'' in a NOT NULL integer column imports as 0");
        self::assertSame('2026-09-01 12:00:00', $david['last_login_at']);
        self::assertNotNull($david['created_at']);

        // Sequence must continue after the highest imported id.
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('new', 'n@x', 'h')");
        self::assertSame(10, (int) $this->db->lastInsertId());

        // Tables absent from the source (seeded feed_sources included) are emptied.
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM feed_sources'));
        self::assertSame(
            count(glob(dirname(__DIR__, 2) . '/database/pgsql/*.sql')),
            (int) $this->db->fetchScalar('SELECT COUNT(*) FROM migrations'),
            'target migration history untouched'
        );
    }

    public function testOrphansAbortPreflightByDefault(): void
    {
        $plan = (new SqliteToPgsqlImporter($this->source(true), $this->db->pdo()))->preflight();

        self::assertCount(1, $plan['errors']);
        self::assertStringContainsString('user_id=77 has no users.id', $plan['errors'][0]);
    }

    public function testSkipOrphansDropsOnlyOrphanRows(): void
    {
        $importer = new SqliteToPgsqlImporter($this->source(true), $this->db->pdo(), skipOrphans: true);
        $plan     = $importer->preflight();

        self::assertSame([], $plan['errors']);
        self::assertSame(['bookmarks' => 1], $plan['skipped']);
        $importer->import($plan);
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM bookmarks'));
    }

    public function testFailedImportRollsBackEverything(): void
    {
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('keep', 'k@x', 'h')");

        $importer = new SqliteToPgsqlImporter($this->source(), $this->db->pdo());
        $plan     = $importer->preflight();
        // Corrupt the plan: duplicate primary key forces a failure mid-import.
        $plan['rows']['bookmarks'][] = $plan['rows']['bookmarks'][0];

        try {
            $importer->import($plan);
            self::fail('expected import to fail');
        } catch (\PDOException) {
            // expected
        }

        self::assertSame(['keep'], array_column($this->db->fetchAll('SELECT username FROM users'), 'username'));
    }
}
