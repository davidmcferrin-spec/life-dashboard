<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\NotificationsHandler;
use Dashboard\Digest\DigestService;
use Dashboard\Notification\NotificationService;
use Dashboard\Support\CapturedResponse;

final class NotificationsTest extends PgsqlTestCase
{
    private NotificationService $notifications;

    private int $user;

    private int $other;

    private string $sessionDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['ada', 'ada@example.com', 'hash', 0]
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['grace', 'grace@example.com', 'hash', 1]
        );
        $this->other = (int) $this->db->lastInsertId();
        $this->notifications = new NotificationService($this->db);
        $this->httpHelpers();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // Another test leaves session.save_path on a directory it then deletes.
        $this->sessionDir = sys_get_temp_dir() . '/lifedash-notify-' . bin2hex(random_bytes(4));
        mkdir($this->sessionDir);
        ini_set('session.save_path', $this->sessionDir);
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cache_limiter', '');
        putenv('LIFEDASH_TEST_CAPTURE=1');
        $_SERVER['REQUEST_URI'] = '/api/notifications';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        unset($_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_X_CSRF_TOKEN']);
    }

    protected function tearDown(): void
    {
        putenv('LIFEDASH_TEST_CAPTURE');
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($this->sessionDir !== '' && is_dir($this->sessionDir)) {
            array_map('unlink', glob($this->sessionDir . '/*') ?: []);
            rmdir($this->sessionDir);
        }
        ini_set('session.save_path', sys_get_temp_dir());
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['HTTP_ACCEPT'], $_POST);
        parent::tearDown();
    }

    public function testListIsPerUserAndMarkReadClearsTheCount(): void
    {
        $first = $this->notifications->create($this->user, 'Pantry item used up', 'Tomatoes are gone.', '/pantry');
        $second = $this->notifications->create($this->user, 'Digest checked');
        $this->notifications->create($this->other, 'Other account');

        $mine = $this->notifications->list($this->user);
        self::assertSame(2, $mine['unread']);
        self::assertSame(['Digest checked', 'Pantry item used up'], array_column($mine['notifications'], 'title'));
        self::assertFalse($mine['notifications'][0]['read']);
        self::assertSame('/pantry', $mine['notifications'][1]['href']);
        self::assertNull($mine['notifications'][0]['href']);
        self::assertSame(1, $this->notifications->list($this->other)['unread']);
        self::assertSame(['Other account'], array_column($this->notifications->list($this->other)['notifications'], 'title'));

        $read = $this->notifications->markRead($this->user, (int) $first['id']);
        self::assertNotNull($read);
        self::assertTrue($read['read']);
        self::assertSame(1, $this->notifications->unreadCount($this->user));
        self::assertNull($this->notifications->markRead($this->user, 999999));
        self::assertNull($this->notifications->markRead($this->other, (int) $second['id']));

        self::assertSame(['unread' => 0], $this->notifications->markAllRead($this->user));
        self::assertSame(0, $this->notifications->unreadCount($this->user));
        self::assertSame(1, $this->notifications->unreadCount($this->other));
    }

    public function testCreateRejectsAnOutsideLinkAndABlankTitle(): void
    {
        foreach (['https://example.com/x', '//example.com', '/\\evil'] as $href) {
            try {
                $this->notifications->create($this->user, 'Open', '', $href);
                self::fail($href . ' was stored');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('path on this dashboard', $e->getMessage());
            }
        }
        try {
            $this->notifications->create($this->user, '   ');
            self::fail('blank title stored');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Title', $e->getMessage());
        }
        self::assertSame(0, $this->notifications->unreadCount($this->user));
    }

    public function testOldestRowsPastTheCapAreDropped(): void
    {
        for ($i = 1; $i <= NotificationService::KEEP + 1; $i++) {
            $this->notifications->create($this->user, 'Note ' . $i);
        }
        $kept = $this->notifications->list($this->user);
        self::assertSame(NotificationService::KEEP, $kept['unread']);
        self::assertSame('Note ' . (NotificationService::KEEP + 1), $kept['notifications'][0]['title']);
        $stored = $this->db->fetchAll('SELECT title FROM notifications WHERE user_id = ?', [$this->user]);
        self::assertCount(NotificationService::KEEP, $stored);
        self::assertNotContains('Note 1', array_column($stored, 'title'));
    }

    public function testHandlerRequiresCsrfAndReturnsTheSavedRow(): void
    {
        $handler = new NotificationsHandler($this->db);
        $user = ['id' => $this->user];

        try {
            $handler->create($user);
            self::fail('missing csrf was accepted');
        } catch (CapturedResponse $response) {
            self::assertSame(403, $response->status);
        }

        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $_SERVER['HTTP_HOST'] = 'dashboard.example';
        $this->armCsrf();
        try {
            $handler->create($user);
            self::fail('cross-site post was accepted');
        } catch (CapturedResponse $response) {
            self::assertSame(403, $response->status);
        }
        unset($_SERVER['HTTP_ORIGIN']);

        $this->armCsrf();
        $_POST = ['title' => 'Flight landed', 'body' => 'Gate B', 'href' => '/flights'];
        try {
            $handler->create($user);
            self::fail('expected a captured response');
        } catch (CapturedResponse $response) {
            self::assertSame(201, $response->status);
            self::assertSame('Flight landed', $response->payload['notification']['title']);
            self::assertSame('/flights', $response->payload['notification']['href']);
            self::assertFalse($response->payload['notification']['read']);
            $id = (int) $response->payload['notification']['id'];
        }

        $this->armCsrf();
        try {
            $handler->markRead($user, ['id' => (string) $id]);
            self::fail('expected a captured response');
        } catch (CapturedResponse $response) {
            self::assertSame(200, $response->status);
            self::assertTrue($response->payload['notification']['read']);
        }

        $this->armCsrf();
        try {
            $handler->markRead($user, ['id' => '0']);
            self::fail('expected a captured response');
        } catch (CapturedResponse $response) {
            self::assertSame(404, $response->status);
        }

        $listed = null;
        try {
            $handler->list($user);
            self::fail('expected a captured response');
        } catch (CapturedResponse $response) {
            self::assertSame(200, $response->status);
            self::assertSame(0, $response->payload['unread']);
            self::assertSame('Flight landed', $response->payload['notifications'][0]['title']);
            $listed = $response->payload;
        }

        $blob = json_encode($listed, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ada@example.com', $blob);
        self::assertStringNotContainsString('password', $blob);
    }

    public function testDigestBadgeCountsEntriesAfterTheReadCursor(): void
    {
        $source = $this->db->fetchOne(
            'INSERT INTO digest_sources (user_id, product, label, url) VALUES (?, ?, ?, ?) RETURNING id',
            [$this->user, 'grok', 'Grok', 'https://docs.x.ai/developers/release-notes']
        );
        $sourceId = (int) ($source['id'] ?? 0);
        $this->db->execute(
            'INSERT INTO digest_entries (user_id, source_id, entry_key, title, url, summary, content_hash, first_seen_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$this->user, $sourceId, 'g1', 'Grok note', 'https://docs.x.ai/developers/release-notes', 'A note.', str_repeat('a', 64), '2026-10-20 12:00:00']
        );
        $digest = new DigestService($this->db);
        self::assertSame(1, $digest->unreadCount($this->user));

        $this->db->execute(
            'INSERT INTO digest_cursors (user_id, read_through_at) VALUES (?, ?)',
            [$this->user, '2026-10-23 00:00:00']
        );
        self::assertSame(0, $digest->unreadCount($this->user));
        self::assertSame(0, $digest->unreadCount($this->other));
    }

    private function armCsrf(): void
    {
        initAppSession();
        $_SESSION['csrf_token'] = 'notify-csrf';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'notify-csrf';
        $_SERVER['REQUEST_URI'] = '/api/notifications';
    }

    private function httpHelpers(): void
    {
        if (function_exists('validateCsrf')) {
            return;
        }
        $src = (string) file_get_contents(ROOT_DIR . '/bootstrap.php');
        $start = strpos($src, 'function requestCsrfToken(): string');
        self::assertNotFalse($start);
        eval('namespace { use Dashboard\\Config; ' . substr($src, $start) . ' }');
    }
}
