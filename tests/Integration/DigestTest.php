<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Deploy\DeployService;
use Dashboard\Digest\DigestService;
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
}
