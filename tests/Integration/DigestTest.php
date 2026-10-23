<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Deploy\DeployService;
use Dashboard\Digest\DigestService;
use Dashboard\Digest\DigestVersion;
use Dashboard\Settings\ServiceControl;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;

final class DigestTest extends PgsqlTestCase
{
    public function testSeedListReadCursorAndAgentWriteGate(): void
    {
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['digest', 'digest@example.com', 'h', 1]
        );
        $userId = (int) $this->db->lastInsertId();
        $spool = sys_get_temp_dir() . '/ld-digest-int-' . bin2hex(random_bytes(4));
        mkdir($spool . '/incoming', 0777, true);
        $digest = new DigestService($this->db, new ServiceControl($spool));

        $digest->ensureSeeded($userId);
        $digest->ensureSeeded($userId);
        $sources = $digest->sources($userId);
        self::assertCount(8, $sources['sources']);

        $this->db->execute(
            'DELETE FROM digest_sources WHERE user_id = ?',
            [$userId]
        );
        $digest->ensureSeeded($userId);
        self::assertSame([], $digest->sources($userId)['sources']);

        $added = $digest->add($userId, 'grok', 'Harbor notes', 'https://example.com/harbor-notes');
        $sourceId = (int) $added['source']['id'];
        $this->db->execute(
            'INSERT INTO digest_entries (user_id, source_id, entry_key, title, published_on, url, summary, content_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$userId, $sourceId, 'harbor', 'North Harbor Lights', '2026-10-02', 'https://example.com/harbor-notes#harbor', 'Sample Ensemble', str_repeat('a', 64)]
        );
        $feed = $digest->items($userId, ['product' => 'grok', 'q' => 'Harbor']);
        self::assertSame(1, $feed['new_count']);
        self::assertTrue($feed['items'][0]['is_new']);
        self::assertSame('North Harbor Lights', $feed['items'][0]['title']);

        $digest->markRead($userId);
        $after = $digest->items($userId, ['unread' => true]);
        self::assertSame(0, $after['new_count']);
        self::assertSame([], $after['items']);

        $this->db->execute(
            'UPDATE digest_entries SET summary = ?, content_hash = ?, edited_at = (
                SELECT read_through_at + INTERVAL \'1 second\' FROM digest_cursors WHERE user_id = ?
             ) WHERE entry_key = ?',
            ['Rewritten bridge', str_repeat('b', 64), $userId, 'harbor']
        );
        $edited = $digest->items($userId, ['unread' => true]);
        self::assertSame(1, $edited['new_count']);
        self::assertTrue($edited['items'][0]['edited']);

        $card = $digest->card($userId);
        self::assertSame(1, $card['new_count']);
        self::assertCount(1, $card['items']);
        self::assertSame([], $card['metric_ids']);

        $digest->setEnabled($userId, $sourceId, false);
        self::assertFalse($digest->sources($userId)['sources'][0]['enabled']);
        $queued = $digest->queueCheck($userId);
        self::assertSame('digest-check', $queued['action']);

        $digest->assertAgentWrite(null);
        try {
            $digest->assertAgentWrite(9);
            self::fail('A bearer write is refused while MCP updates are off.');
        } catch (ForbiddenException $e) {
            self::assertSame(DeployService::MCP_DISABLED, $e->getMessage());
        }
        (new DeployService($this->db))->setMcpUpdates(true, $userId);
        $digest->assertAgentWrite(9);

        try {
            $digest->add($userId, 'grok', 'Local', 'https://127.0.0.1/notes');
            self::fail('A loopback URL must be rejected.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('url', $e->fields());
        }
    }

    public function testDateRangeVersionRangeAndSnapshotRedline(): void
    {
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['digest2', 'digest2@example.com', 'h', 1]
        );
        $userId = (int) $this->db->lastInsertId();
        $digest = new DigestService($this->db);
        $digest->ensureSeeded($userId);
        self::assertSame(180, $digest->sources($userId)['retention_days']);

        $cursorId = $this->sourceId($userId, 'cursor_ide');
        $botId = $this->sourceId($userId, 'grok_bot');
        $this->entry($userId, $cursorId, 'c17', 'Cursor 1.7', '2026-09-01', '1.7');
        $this->entry($userId, $cursorId, 'c110', 'Cursor 1.10', '2026-10-02', '1.10');
        $this->entry($userId, $cursorId, 'c20', 'Cursor 2.0', '2026-10-20', '2.0');
        $this->entry($userId, $cursorId, 'old', 'Old Harbor', '2026-01-01', null);
        $this->db->execute(
            'UPDATE digest_entries SET edited_at = ? WHERE user_id = ? AND entry_key = ?',
            ['2026-10-15 12:00:00', $userId, 'old']
        );
        $this->entry($userId, $cursorId, 'aug', 'August note', '2026-08-01', null);
        $this->entry($userId, $botId, 'bot', 'Bot (0.71.2)', '2026-10-14', '0.71.2');

        $dates = $digest->compare($userId, ['from_date' => '2026-10-31', 'to_date' => '2026-10-01']);
        self::assertSame('date', $dates['mode']);
        self::assertSame('2026-10-01', $dates['from']);
        self::assertSame(4, $dates['count']);
        self::assertSame('Cursor IDE', $dates['groups'][0]['product_label']);
        self::assertSame(3, $dates['groups'][0]['count']);
        self::assertSame('Grok Bot', $dates['groups'][1]['product_label']);
        self::assertSame(1, $dates['groups'][1]['count']);

        $versions = $digest->compare($userId, [
            'product' => 'cursor_ide',
            'from_version' => '2.0',
            'to_version' => '1.7',
        ]);
        self::assertSame(['1.7', '1.10', '2.0'], array_column($versions['items'], 'version'));
        self::assertSame(3, $versions['count']);

        $narrow = $digest->compare($userId, [
            'product' => 'cursor_ide',
            'from_version' => '1.7',
            'to_version' => '1.10',
        ]);
        self::assertSame(['1.7', '1.10'], array_column($narrow['items'], 'version'));

        $this->db->execute(
            'INSERT INTO digest_snapshots (user_id, source_id, captured_on, content_hash, body)
             VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
            [
                $userId, $cursorId, '2026-10-01', str_repeat('a', 64), 'keep <script>',
                $userId, $cursorId, '2026-10-20', str_repeat('b', 64), 'keep <script> lights',
            ]
        );
        $diff = $digest->snapshotDiff($userId, $cursorId, '2026-10-20', '2026-10-01');
        self::assertSame('2026-10-01', $diff['from_captured_on']);
        self::assertSame('2026-10-20', $diff['to_captured_on']);
        self::assertStringContainsString('&lt;script&gt;', $diff['diff']['html']);
        self::assertStringContainsString('<ins>', $diff['diff']['html']);
        self::assertStringNotContainsString('<script', $diff['diff']['html']);

        $today = (string) $this->db->fetchScalar("SELECT CAST((now() AT TIME ZONE 'UTC') AS date)");
        $this->db->execute(
            'INSERT INTO digest_snapshots (user_id, source_id, captured_on, content_hash, body)
             VALUES (?, ?, ?, ?, ?), (?, ?, ?, ?, ?)',
            [
                $userId, $botId, '2001-01-01', str_repeat('c', 64), 'old snapshot',
                $userId, $botId, $today, str_repeat('d', 64), 'today snapshot',
            ]
        );
        self::assertSame(30, $digest->setRetention($userId, 30)['retention_days']);
        $left = $this->db->fetchAll(
            'SELECT captured_on FROM digest_snapshots WHERE user_id = ? AND source_id = ? ORDER BY captured_on',
            [$userId, $botId]
        );
        self::assertSame([$today], array_map(static fn (array $row): string => (string) $row['captured_on'], $left));
    }

    private function sourceId(int $userId, string $product): int
    {
        $id = $this->db->fetchScalar(
            'SELECT id FROM digest_sources WHERE user_id = ? AND product = ? ORDER BY id LIMIT 1',
            [$userId, $product]
        );
        self::assertNotFalse($id);
        return (int) $id;
    }

    private function entry(int $userId, int $sourceId, string $key, string $title, string $day, ?string $version): void
    {
        $parsed = $version === null ? null : DigestVersion::parse($version);
        $this->db->execute(
            'INSERT INTO digest_entries (user_id, source_id, entry_key, title, published_on, url, summary, content_hash, version_text, version_sort)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $sourceId, $key, $title, $day, 'https://notes.example.com/' . $key, 'Sample Ensemble',
                str_repeat('e', 64),
                $parsed['version_text'] ?? null,
                $parsed['version_sort'] ?? null,
            ]
        );
    }
}
