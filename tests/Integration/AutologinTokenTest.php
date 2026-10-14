<?php

declare(strict_types=1);

namespace {
    if (!function_exists('initAppSession')) {
        function initAppSession(): void
        {
            if (session_status() === PHP_SESSION_ACTIVE) {
                return;
            }
            session_name('dashboard_sess_test');
            session_start();
        }
    }
}

namespace Dashboard\Tests\Integration {

use Dashboard\Auth\Auth;

/**
 * Auto-login secrets are stored as SHA-256. Migration 0011 hashes rows that
 * still have the old plaintext column, and a presented hash is not itself a token.
 */
final class AutologinTokenTest extends PgsqlTestCase
{
    private string $sessionDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        putenv('APP_SECRET=autologin-test-secret-autologin-test-secret');
        $this->sessionDir = sys_get_temp_dir() . '/lifedash-sess-' . bin2hex(random_bytes(4));
        mkdir($this->sessionDir);
        ini_set('session.save_path', $this->sessionDir);
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cache_limiter', '');
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($this->sessionDir !== '' && is_dir($this->sessionDir)) {
            array_map('unlink', glob($this->sessionDir . '/*') ?: []);
            rmdir($this->sessionDir);
        }
        parent::tearDown();
    }

    public function testCreateStoresHashAndPlaintextStillSignsIn(): void
    {
        $userId = $this->insertUser();
        $plain  = (new Auth($this->db))->createToken($userId, 'phone');

        $columns = $this->columnNames();
        self::assertContains('token_hash', $columns);
        self::assertNotContains('token', $columns);

        $row = $this->db->fetchOne('SELECT token_hash, label FROM autologin_tokens WHERE user_id = ?', [$userId]);
        self::assertSame(Auth::hashToken($plain), rtrim((string) $row['token_hash']));
        self::assertNotSame($plain, rtrim((string) $row['token_hash']));

        $auth = new Auth($this->db);
        $user = $auth->attemptWithToken($plain);
        self::assertSame('ada', $user['username'] ?? null);
        self::assertNull($auth->attemptWithToken((string) $row['token_hash']));
    }

    public function testMigrationHashesAnExistingPlaintextRow(): void
    {
        $plain = str_repeat('ab', 32);
        $digest = (string) $this->db->fetchScalar(
            "SELECT encode(digest(CAST(? AS text), 'sha256'), 'hex')",
            [$plain]
        );
        self::assertSame(hash('sha256', $plain), $digest, 'pgcrypto digest must match PHP hash()');

        $userId = $this->insertUser();
        $this->db->pdo()->exec('ALTER TABLE autologin_tokens DROP COLUMN token_hash');
        $this->db->pdo()->exec('ALTER TABLE autologin_tokens ADD COLUMN token VARCHAR(64)');
        $this->db->execute(
            'INSERT INTO autologin_tokens (user_id, token, label) VALUES (?, ?, ?)',
            [$userId, $plain, 'old phone']
        );
        $this->db->execute('DELETE FROM migrations WHERE name = ?', ['0011_autologin_token_hash']);

        $this->migrate();

        $row = $this->db->fetchOne('SELECT token_hash FROM autologin_tokens WHERE user_id = ?', [$userId]);
        self::assertSame(hash('sha256', $plain), rtrim((string) $row['token_hash']));
        self::assertNotContains('token', $this->columnNames());

        $user = (new Auth($this->db))->attemptWithToken($plain);
        self::assertSame('ada', $user['username'] ?? null);
    }

    private function insertUser(): int
    {
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        return (int) $this->db->lastInsertId();
    }

    /** @return list<string> */
    private function columnNames(): array
    {
        return array_column(
            $this->db->fetchAll(
                "SELECT column_name FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = 'autologin_tokens'"
            ),
            'column_name'
        );
    }
}
}
