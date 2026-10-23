<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Deploy\DeployService;
use Dashboard\Digest\DigestService;
use Dashboard\Digest\DigestVersion;
use Dashboard\Settings\ServiceControl;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\NotFoundException;
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
        self::assertSame('published', $feed['items'][0]['date_source']);

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

        $long = 'Sample Ensemble kept the bridge quiet. The lights stayed low. A third sentence belongs in the full note and not in the card line that people scan first while deciding whether to open it.';
        $this->db->execute(
            'UPDATE digest_entries SET summary = ? WHERE user_id = ? AND entry_key = ?',
            [$long, $userId, 'harbor']
        );
        $shown = $digest->items($userId, ['q' => 'Harbor', 'limit' => 12]);
        self::assertSame('Sample Ensemble kept the bridge quiet. The lights stayed low.', $shown['items'][0]['summary']);
        self::assertSame($long, $shown['items'][0]['body']);
        self::assertSame('2026-10-02', $shown['items'][0]['day']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $shown['today']);
        $this->db->execute(
            "UPDATE digest_entries SET brief = ?, brief_kind = 'ollama' WHERE user_id = ? AND entry_key = ?",
            ['A stored line about the harbor.', $userId, 'harbor']
        );
        $stored = $digest->items($userId, ['q' => 'lantern']);
        self::assertSame([], $stored['items']);
        $this->db->execute(
            'UPDATE digest_entries SET brief = ? WHERE user_id = ? AND entry_key = ?',
            ['A stored line about the lantern.', $userId, 'harbor']
        );
        $foundBrief = $digest->items($userId, ['q' => 'lantern']);
        self::assertSame('A stored line about the lantern.', $foundBrief['items'][0]['summary']);

        for ($i = 0; $i < 12; $i++) {
            $this->db->execute(
                'INSERT INTO digest_entries (user_id, source_id, entry_key, title, published_on, url, summary, content_hash)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$userId, $sourceId, 'extra-' . $i, 'Extra ' . $i, '2026-10-03', 'https://example.com/extra-' . $i, 'Line', hash('sha256', 'extra-' . $i)]
            );
        }
        $page = $digest->items($userId, ['limit' => 12]);
        self::assertCount(12, $page['items']);
        self::assertTrue($page['has_more']);
        $rest = $digest->items($userId, ['limit' => 12, 'offset' => 12]);
        self::assertCount(1, $rest['items']);
        self::assertFalse($rest['has_more']);

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

    public function testStoredDateIsPeeledWithoutMarkingTheRowEdited(): void
    {
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['digest3', 'digest3@example.com', 'h', 1]
        );
        $userId = (int) $this->db->lastInsertId();
        $digest = new DigestService($this->db);
        $digest->ensureSeeded($userId);
        $sourceId = $this->sourceId($userId, 'grok');
        $glued = 'We are releasing the model Grok-1. Mar 17, 2024';
        $this->db->execute(
            'INSERT INTO digest_entries (user_id, source_id, entry_key, title, url, summary, brief, brief_kind, content_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $sourceId, 'sample-1', 'Open Release of Grok-1', 'https://example.com/sample-1',
                $glued, $glued, 'ollama', str_repeat('c', 64),
            ]
        );
        $feed = $digest->items($userId, ['q' => 'Grok-1']);
        self::assertSame('2024-03-17', $feed['items'][0]['published_on']);
        self::assertSame('published', $feed['items'][0]['date_source']);
        self::assertSame('We are releasing the model Grok-1.', $feed['items'][0]['body']);
        self::assertSame('We are releasing the model Grok-1.', $feed['items'][0]['summary']);
        self::assertStringNotContainsString('Mar 17', $feed['items'][0]['summary']);
        $row = $this->db->fetchOne(
            'SELECT published_on, summary, brief, brief_kind, content_hash, edited_at FROM digest_entries WHERE user_id = ? AND entry_key = ?',
            [$userId, 'sample-1']
        );
        self::assertNotNull($row);
        self::assertNull($row['edited_at']);
        self::assertSame('excerpt', $row['brief_kind']);
        self::assertSame('02cffed9d1a3f454f1234d663139f42ec7bdf13a2fa9e1cdef58794b28b82aba', $row['content_hash']);
        $again = $digest->items($userId, ['q' => 'Grok-1']);
        self::assertSame($feed['items'][0]['body'], $again['items'][0]['body']);
        self::assertSame('2024-03-17', $again['items'][0]['published_on']);
        $edited = $this->db->fetchScalar(
            'SELECT edited_at FROM digest_entries WHERE user_id = ? AND entry_key = ?',
            [$userId, 'sample-1']
        );
        self::assertNull($edited);

        $this->db->execute(
            'INSERT INTO digest_entries (user_id, source_id, entry_key, title, url, summary, content_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $sourceId, 'undated', 'Harbor sketch', 'https://example.com/undated',
                'Sample Ensemble', str_repeat('d', 64),
            ]
        );
        $found = $digest->items($userId, ['q' => 'sketch']);
        self::assertNull($found['items'][0]['published_on']);
        self::assertSame('found', $found['items'][0]['date_source']);
        self::assertNotNull($found['items'][0]['day']);
    }

    public function testTopicTagsFilterTheFeedAndSourceCountsStayReal(): void
    {
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['digest4', 'digest4@example.com', 'h', 1]
        );
        $userId = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['digest5', 'digest5@example.com', 'h', 0]
        );
        $otherId = (int) $this->db->lastInsertId();
        $digest = new DigestService($this->db);
        $digest->ensureSeeded($userId);
        $grok = $this->sourceId($userId, 'grok');
        $bot = $this->sourceId($userId, 'grok_bot');
        $shared = 'https://notes.example.com/shared-harbor';
        $this->db->execute(
            'INSERT INTO digest_entries (user_id, source_id, entry_key, title, published_on, url, summary, content_hash)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $userId, $grok, 'shared-a', 'Harbor Lights', '2026-10-02', $shared, 'Sample Ensemble', hash('sha256', 'shared-a'),
                $userId, $bot, 'shared-b', 'Harbor Lights copy', '2026-10-02', $shared, 'Sample Ensemble', hash('sha256', 'shared-b'),
                $userId, $grok, 'solo', 'Solo note', '2026-10-03', 'https://notes.example.com/solo', 'Sample Ensemble', hash('sha256', 'solo'),
            ]
        );
        $feed = $digest->items($userId, ['q' => 'Harbor']);
        self::assertSame([], $feed['topics']);
        self::assertCount(2, $feed['items']);
        self::assertSame(2, $feed['items'][0]['source_count']);
        self::assertSame(2, $feed['items'][1]['source_count']);
        self::assertSame([], $feed['items'][0]['topics']);
        $solo = $digest->items($userId, ['q' => 'Solo']);
        self::assertSame(1, $solo['items'][0]['source_count']);

        $entryId = 0;
        foreach ($feed['items'] as $item) {
            if ((int) $item['source_id'] === $grok) {
                $entryId = (int) $item['id'];
            }
        }
        self::assertGreaterThan(0, $entryId);
        $tagged = $digest->tag($userId, $entryId, '  Harbor   Lights ');
        self::assertSame('Harbor Lights', $tagged['topic']['label']);
        self::assertSame('harbor lights', $tagged['topic']['slug']);
        $again = $digest->tag($userId, $entryId, 'harbor lights');
        self::assertSame($tagged['topic']['id'], $again['topic']['id']);
        self::assertSame('Harbor Lights', $again['topic']['label']);
        self::assertCount(1, $again['topics']);

        $filtered = $digest->items($userId, ['topic' => 'Harbor Lights']);
        self::assertCount(1, $filtered['items']);
        self::assertSame($entryId, $filtered['items'][0]['id']);
        self::assertSame('Harbor Lights', $filtered['items'][0]['topics'][0]['label']);
        self::assertSame('Harbor Lights', $filtered['topics'][0]['label']);
        self::assertSame([], $digest->items($userId, ['topic' => 'missing topic'])['items']);

        try {
            $digest->tag($userId, $entryId, '!!!');
            self::fail('A topic needs a letter or number.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('name', $e->fields());
        }
        try {
            $digest->tag($otherId, $entryId, 'Sneaky');
            self::fail('Another user cannot tag this entry.');
        } catch (NotFoundException $e) {
            self::assertSame('Entry not found.', $e->getMessage());
        }

        self::assertSame([], $digest->untag($userId, $entryId, (int) $tagged['topic']['id'])['topics']);
        self::assertSame([], $digest->items($userId)['topics']);
        $digest->tag($userId, $entryId, 'Harbor Lights');
        $digest->remove($userId, $grok);
        self::assertSame([], $digest->items($userId)['topics']);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM digest_topics WHERE user_id = ?', [$userId]));
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
