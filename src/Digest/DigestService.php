<?php

declare(strict_types=1);

namespace Dashboard\Digest;

use Dashboard\DB\Database;
use Dashboard\Deploy\DeployService;
use Dashboard\Knowledge\Redline;
use Dashboard\Settings\ServiceControl;
use Dashboard\Spotify\SpotifyAccess;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

final class DigestService
{
    public const RETENTION_KEY = 'digest_snapshot_days';

    public const RETENTION_DEFAULT = 180;

    public const COMPARE_LIMIT = 300;

    private ?Clock $clock = null;

    public function __construct(
        private readonly Database $db,
        private readonly ?ServiceControl $control = null,
    ) {}

    public static function fromDatabase(): self
    {
        return new self(Database::getInstance());
    }

    public function assertAgentWrite(?int $tokenId): void
    {
        SpotifyAccess::assertChange($tokenId, (new DeployService($this->db))->mcpUpdatesEnabled());
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function items(int $userId, array $filter = []): array
    {
        $this->ensureSeeded($userId);
        $this->repairStored($userId);
        $product = $this->productFilter($filter['product'] ?? null);
        $since = $this->sinceFilter($filter['since'] ?? null);
        $unread = !empty($filter['unread']);
        $query = $this->search($filter['q'] ?? null);
        $topic = $this->topicFilter($filter['topic'] ?? null);
        $limit = $this->limit($filter['limit'] ?? 50);
        $offset = $this->offset($filter['offset'] ?? 0);
        $cursor = $this->cursor($userId);
        $rows = [];
        $found = $this->db->fetchAll(
            'SELECT e.id, e.title, e.published_on, e.url, e.summary, e.brief, e.first_seen_at, e.edited_at, e.version_text,
                    s.product, s.label AS source_label, s.id AS source_id,
                    (SELECT COUNT(DISTINCT e2.source_id) FROM digest_entries e2 WHERE e2.user_id = e.user_id AND e2.url = e.url) AS source_count,
                    (SELECT COALESCE(json_agg(json_build_object(\'id\', t.id, \'label\', t.label, \'slug\', t.slug) ORDER BY t.label), \'[]\'::json)
                     FROM digest_entry_topics et
                     JOIN digest_topics t ON t.id = et.topic_id
                     WHERE et.entry_id = e.id) AS topics
             FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ?
               AND (? = 0 OR s.product = ?)
               AND (? = 0 OR (e.published_on IS NOT NULL AND e.published_on >= ?) OR (e.published_on IS NULL AND e.first_seen_at >= ?))
               AND (? = 0 OR e.title ILIKE ? OR e.summary ILIKE ? OR e.brief ILIKE ?)
               AND (? = 0 OR e.first_seen_at > ? OR (e.edited_at IS NOT NULL AND e.edited_at > ?))
               AND (? = 0 OR EXISTS (
                    SELECT 1 FROM digest_entry_topics etf
                    JOIN digest_topics tpf ON tpf.id = etf.topic_id
                    WHERE etf.entry_id = e.id AND tpf.slug = ?
               ))
             ORDER BY COALESCE(e.published_on, CAST(e.first_seen_at AS date)) DESC, e.first_seen_at DESC, e.id DESC
             LIMIT ? OFFSET ?',
            [
                $userId,
                $product === null ? 0 : 1, $product ?? '',
                $since === null ? 0 : 1, $since ?? '1970-01-01', ($since ?? '1970-01-01') . ' 00:00:00',
                $query === null ? 0 : 1, $query ?? '%', $query ?? '%', $query ?? '%',
                ($unread && $cursor !== null) ? 1 : 0, $cursor ?? '1970-01-01 00:00:00', $cursor ?? '1970-01-01 00:00:00',
                $topic === null ? 0 : 1, $topic ?? '',
                $limit + 1,
                $offset,
            ]
        );
        $hasMore = count($found) > $limit;
        if ($hasMore) {
            array_pop($found);
        }
        foreach ($found as $row) {
            $rows[] = $this->presentItem($row, $cursor);
        }
        return [
            'items'           => $rows,
            'new_count'       => $this->newCount($userId, $product, $topic),
            'read_through_at' => $this->stamp($cursor),
            'products'        => $this->products(),
            'topics'          => $this->topicChips($userId),
            'has_more'        => $hasMore,
            'today'           => $this->clock()->today(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function card(int $userId): array
    {
        $feed = $this->items($userId, ['limit' => 3]);
        return [
            'metric_ids' => [],
            'new_count'  => $feed['new_count'],
            'items'      => $feed['items'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sources(int $userId): array
    {
        $this->ensureSeeded($userId);
        $rows = $this->db->fetchAll(
            'SELECT id, product, label, url, enabled, last_checked_at, status, last_error, parse_mode
             FROM digest_sources WHERE user_id = ? ORDER BY product, label, id',
            [$userId]
        );
        $sources = [];
        foreach ($rows as $row) {
            $product = (string) $row['product'];
            $sources[] = [
                'id'              => (int) $row['id'],
                'product'         => $product,
                'product_label'   => DigestCatalog::PRODUCTS[$product] ?? $product,
                'label'           => (string) $row['label'],
                'url'             => (string) $row['url'],
                'enabled'         => (int) $row['enabled'] === 1,
                'last_checked_at' => $this->stamp(isset($row['last_checked_at']) ? (string) $row['last_checked_at'] : null),
                'status'          => (string) $row['status'],
                'last_error'      => $row['last_error'] !== null ? (string) $row['last_error'] : null,
                'parse_mode'      => $row['parse_mode'] !== null ? (string) $row['parse_mode'] : null,
            ];
        }
        return [
            'sources' => $sources,
            'products' => $this->products(),
            'retention_days' => $this->retentionDays($userId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function add(int $userId, string $product, string $label, string $url): array
    {
        $this->ensureSeeded($userId);
        $product = DigestCatalog::product($product);
        $label = trim($label);
        if ($label === '' || strlen($label) > 80) {
            throw ValidationException::withFields(['label' => 'Label must be 1 to 80 characters.']);
        }
        $url = DigestUrl::assert($url);
        $existing = $this->db->fetchOne(
            'SELECT id FROM digest_sources WHERE user_id = ? AND url = ?',
            [$userId, $url]
        );
        if ($existing !== null) {
            throw ValidationException::withFields(['url' => 'That source is already listed.']);
        }
        $row = $this->db->fetchOne(
            'INSERT INTO digest_sources (user_id, product, label, url) VALUES (?, ?, ?, ?) RETURNING id',
            [$userId, $product, $label, $url]
        );
        return $this->source($userId, (int) ($row['id'] ?? 0));
    }

    /**
     * @return array<string, mixed>
     */
    public function setEnabled(int $userId, int $id, bool $enabled): array
    {
        $this->owned($userId, $id);
        $this->db->execute(
            'UPDATE digest_sources SET enabled = ? WHERE user_id = ? AND id = ?',
            [$enabled ? 1 : 0, $userId, $id]
        );
        return $this->source($userId, $id);
    }

    public function remove(int $userId, int $id): void
    {
        $this->owned($userId, $id);
        $this->db->execute('DELETE FROM digest_sources WHERE user_id = ? AND id = ?', [$userId, $id]);
        $this->dropUnusedTopics($userId);
    }

    /**
     * @return array{topic: array{id: int, label: string, slug: string}, topics: list<array{id: int, label: string, slug: string}>}
     */
    public function tag(int $userId, int $entryId, string $name): array
    {
        $this->ownedEntry($userId, $entryId);
        $named = DigestTopics::name($name);
        $this->db->execute(
            'INSERT INTO digest_topics (user_id, slug, label) VALUES (?, ?, ?)
             ON CONFLICT (user_id, slug) DO NOTHING',
            [$userId, $named['slug'], $named['label']]
        );
        $topic = $this->db->fetchOne(
            'SELECT id, label, slug FROM digest_topics WHERE user_id = ? AND slug = ?',
            [$userId, $named['slug']]
        );
        if ($topic === null) {
            throw new NotFoundException('Topic not found.');
        }
        $topicId = (int) $topic['id'];
        $this->db->execute(
            'INSERT INTO digest_entry_topics (user_id, entry_id, topic_id) VALUES (?, ?, ?)
             ON CONFLICT (entry_id, topic_id) DO NOTHING',
            [$userId, $entryId, $topicId]
        );
        return [
            'topic' => [
                'id' => $topicId,
                'label' => (string) $topic['label'],
                'slug' => (string) $topic['slug'],
            ],
            'topics' => $this->entryTopics($userId, $entryId),
        ];
    }

    /**
     * @return array{topics: list<array{id: int, label: string, slug: string}>}
     */
    public function untag(int $userId, int $entryId, int $topicId): array
    {
        $this->ownedEntry($userId, $entryId);
        $this->db->execute(
            'DELETE FROM digest_entry_topics WHERE user_id = ? AND entry_id = ? AND topic_id = ?',
            [$userId, $entryId, $topicId]
        );
        $this->dropUnusedTopics($userId);
        return ['topics' => $this->entryTopics($userId, $entryId)];
    }

    /**
     * @return array{read_through_at: string}
     */
    public function markRead(int $userId): array
    {
        $row = $this->db->fetchOne(
            "INSERT INTO digest_cursors (user_id, read_through_at)
             VALUES (?, (now() AT TIME ZONE 'UTC'))
             ON CONFLICT (user_id) DO UPDATE SET read_through_at = EXCLUDED.read_through_at
             RETURNING read_through_at",
            [$userId]
        );
        return ['read_through_at' => $this->stamp((string) ($row['read_through_at'] ?? '')) ?? ''];
    }

    /**
     * @return array{id: string, action: string, queued: bool}
     */
    public function queueCheck(int $userId): array
    {
        $control = $this->control ?? new ServiceControl();
        return $control->request('digest-check', ['user_id' => (string) $userId]);
    }

    public function ensureSeeded(int $userId): void
    {
        $flag = $this->db->fetchScalar(
            'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, DigestCatalog::SEED_FLAG]
        );
        if ($flag !== null) {
            return;
        }
        foreach (DigestCatalog::seeds() as $seed) {
            $this->db->execute(
                'INSERT INTO digest_sources (user_id, product, label, url) VALUES (?, ?, ?, ?)
                 ON CONFLICT (user_id, url) DO NOTHING',
                [$userId, $seed['product'], $seed['label'], $seed['url']]
            );
        }
        $this->db->execute(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)
             ON CONFLICT (user_id, pref_key) DO NOTHING',
            [$userId, DigestCatalog::SEED_FLAG, '1']
        );
    }

    /** Pull a date glued onto a stored note. A date-only cleanup leaves edited_at alone. */
    private function repairStored(int $userId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id, title, published_on, summary, brief, brief_kind, content_hash
             FROM digest_entries WHERE user_id = ?',
            [$userId]
        );
        foreach ($rows as $row) {
            $published = $row['published_on'] !== null && (string) $row['published_on'] !== ''
                ? substr((string) $row['published_on'], 0, 10)
                : null;
            $fixed = DigestDates::repair(
                (string) ($row['title'] ?? ''),
                $published,
                (string) ($row['summary'] ?? ''),
                (string) ($row['brief'] ?? ''),
                (string) ($row['brief_kind'] ?? ''),
            );
            if ($fixed === null) {
                continue;
            }
            $this->db->execute(
                'UPDATE digest_entries
                 SET summary = ?, published_on = ?, brief = ?, brief_kind = ?, content_hash = ?
                 WHERE id = ? AND content_hash = ?',
                [
                    $fixed['summary'],
                    $fixed['published_on'],
                    $fixed['brief'],
                    $fixed['brief_kind'],
                    $fixed['content_hash'],
                    (int) $row['id'],
                    (string) $row['content_hash'],
                ]
            );
        }
    }

    private function newCount(int $userId, ?string $product = null, ?string $topic = null): int
    {
        $cursor = $this->cursor($userId);
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ?
               AND (? = 0 OR s.product = ?)
               AND (? = 0 OR e.first_seen_at > ? OR (e.edited_at IS NOT NULL AND e.edited_at > ?))
               AND (? = 0 OR EXISTS (
                    SELECT 1 FROM digest_entry_topics etf
                    JOIN digest_topics tpf ON tpf.id = etf.topic_id
                    WHERE etf.entry_id = e.id AND tpf.slug = ?
               ))',
            [
                $userId,
                $product === null ? 0 : 1, $product ?? '',
                $cursor === null ? 0 : 1, $cursor ?? '1970-01-01 00:00:00', $cursor ?? '1970-01-01 00:00:00',
                $topic === null ? 0 : 1, $topic ?? '',
            ]
        );
    }

    private function cursor(int $userId): ?string
    {
        $value = $this->db->fetchScalar(
            'SELECT read_through_at FROM digest_cursors WHERE user_id = ?',
            [$userId]
        );
        return $value !== null && $value !== '' ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentItem(array $row, ?string $cursor): array
    {
        $product = (string) $row['product'];
        $first = (string) $row['first_seen_at'];
        $edited = $row['edited_at'] !== null ? (string) $row['edited_at'] : null;
        $isNew = $cursor === null || $first > $cursor || ($edited !== null && $edited > $cursor);
        $body = (string) $row['summary'];
        $stored = trim((string) ($row['brief'] ?? ''));
        $published = $row['published_on'] !== null && (string) $row['published_on'] !== ''
            ? substr((string) $row['published_on'], 0, 10)
            : null;
        return [
            'id'            => (int) $row['id'],
            'title'         => (string) $row['title'],
            'published_on'  => $published,
            'date_source'   => $published !== null ? 'published' : 'found',
            'day'           => $this->itemDay($row),
            'url'           => (string) $row['url'],
            'summary'       => $stored !== '' ? $stored : DigestBrief::excerpt($body),
            'body'          => $body,
            'first_seen_at' => $this->stamp($first),
            'edited_at'     => $this->stamp($edited),
            'product'       => $product,
            'product_label' => DigestCatalog::PRODUCTS[$product] ?? $product,
            'source_label'  => (string) $row['source_label'],
            'source_id'     => (int) $row['source_id'],
            'is_new'        => $isNew,
            'edited'        => $edited !== null,
            'version'       => isset($row['version_text']) && $row['version_text'] !== null && $row['version_text'] !== ''
                ? (string) $row['version_text']
                : null,
            // Same changelog URL on two sources is one story, so the badge counts sources.
            'source_count'  => max(1, (int) ($row['source_count'] ?? 1)),
            'topics'        => $this->decodeTopics($row['topics'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function compare(int $userId, array $filter): array
    {
        $this->ensureSeeded($userId);
        $this->repairStored($userId);
        $hasDate = $this->filled($filter['from_date'] ?? null) || $this->filled($filter['to_date'] ?? null);
        $hasVersion = $this->filled($filter['from_version'] ?? null) || $this->filled($filter['to_version'] ?? null);
        if ($hasDate && $hasVersion) {
            throw ValidationException::withFields(['compare' => 'Compare dates or versions, not both.']);
        }
        if ($hasDate) {
            return $this->compareDates($userId, $filter);
        }
        if ($hasVersion) {
            return $this->compareVersions($userId, $filter);
        }
        throw ValidationException::withFields(['compare' => 'Choose a from and to date, or a from and to version.']);
    }

    /**
     * @return list<array{version: string, version_sort: string}>
     */
    public function versions(int $userId, string $product): array
    {
        $this->ensureSeeded($userId);
        $product = DigestCatalog::product($product);
        $rows = $this->db->fetchAll(
            'SELECT version_text, MIN(version_sort) AS version_sort
             FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ? AND s.product = ? AND version_text IS NOT NULL
             GROUP BY version_text
             ORDER BY MIN(version_sort)',
            [$userId, $product]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'version' => (string) $row['version_text'],
                'version_sort' => (string) $row['version_sort'],
            ];
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshotDiff(int $userId, int $sourceId, string $from, string $to): array
    {
        $this->owned($userId, $sourceId);
        $from = $this->day($from, 'from');
        $to = $this->day($to, 'to');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $before = $this->snapshotOn($userId, $sourceId, $from);
        $after = $this->snapshotOn($userId, $sourceId, $to);
        if ($before === null) {
            throw ValidationException::withFields(['from' => 'No snapshot on or before that date.']);
        }
        if ($after === null) {
            throw ValidationException::withFields(['to' => 'No snapshot on or before that date.']);
        }
        return [
            'source_id' => $sourceId,
            'from' => $from,
            'to' => $to,
            'from_captured_on' => (string) $before['captured_on'],
            'to_captured_on' => (string) $after['captured_on'],
            'diff' => Redline::compare((string) $before['body'], (string) $after['body']),
        ];
    }

    /**
     * @return array{retention_days: int}
     */
    public function setRetention(int $userId, int $days): array
    {
        if ($days < 1 || $days > 3650) {
            throw ValidationException::withFields(['days' => 'Keep snapshots between 1 and 3650 days.']);
        }
        $this->db->execute(
            'INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, ?, ?)
             ON CONFLICT (user_id, pref_key) DO UPDATE SET pref_value = EXCLUDED.pref_value',
            [$userId, self::RETENTION_KEY, (string) $days]
        );
        $this->db->execute(
            'DELETE FROM digest_snapshots
             WHERE user_id = ? AND captured_on < (CAST((now() AT TIME ZONE \'UTC\') AS date) - CAST(? AS integer))',
            [$userId, $days]
        );
        return ['retention_days' => $days];
    }

    /**
     * @return array<string, mixed>
     */
    private function source(int $userId, int $id): array
    {
        $payload = $this->sources($userId);
        foreach ($payload['sources'] as $source) {
            if ((int) $source['id'] === $id) {
                return ['source' => $source];
            }
        }
        throw new NotFoundException('Source not found.');
    }

    private function owned(int $userId, int $id): void
    {
        $row = $this->db->fetchOne('SELECT id FROM digest_sources WHERE user_id = ? AND id = ?', [$userId, $id]);
        if ($row === null) {
            throw new NotFoundException('Source not found.');
        }
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function products(): array
    {
        $out = [];
        foreach (DigestCatalog::PRODUCTS as $id => $label) {
            $out[] = ['id' => $id, 'label' => $label];
        }
        return $out;
    }

    private function productFilter(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return DigestCatalog::product((string) $value);
    }

    private function sinceFilter(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            throw ValidationException::withFields(['since' => 'since must be YYYY-MM-DD.']);
        }
        return $value;
    }

    private function search(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (strlen($value) > 80) {
            throw ValidationException::withFields(['q' => 'Search must be 80 characters or less.']);
        }
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
        return '%' . $escaped . '%';
    }

    private function limit(mixed $value): int
    {
        $limit = (int) $value;
        if ($limit < 1) {
            return 50;
        }
        return min(200, $limit);
    }

    private function offset(mixed $value): int
    {
        $offset = (int) $value;
        if ($offset < 1) {
            return 0;
        }

        return min(5000, $offset);
    }

    private function clock(): Clock
    {
        return $this->clock ??= Clock::fromConfig();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function itemDay(array $row): ?string
    {
        if ($row['published_on'] !== null && (string) $row['published_on'] !== '') {
            return (string) $row['published_on'];
        }
        $first = str_replace('T', ' ', (string) $row['first_seen_at']);
        $first = rtrim($first, 'Z');
        try {
            $utc = new \DateTimeImmutable($first, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $this->clock()->localDateOf($utc);
    }

    private function stamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace(' ', 'T', $value);
        return str_ends_with($value, 'Z') ? $value : $value . 'Z';
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function compareDates(int $userId, array $filter): array
    {
        if (!$this->filled($filter['from_date'] ?? null) || !$this->filled($filter['to_date'] ?? null)) {
            throw ValidationException::withFields(['from_date' => 'Choose a from and to date.']);
        }
        $from = $this->day($filter['from_date'], 'from_date');
        $to = $this->day($filter['to_date'], 'to_date');
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        $product = $this->productFilter($filter['product'] ?? null);
        $flag = $product === null ? 0 : 1;
        $args = [$userId, $flag, $product ?? '', $from, $to, $from, $to, $from, $to];
        $total = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ?
               AND (? = 0 OR s.product = ?)
               AND (
                    (e.published_on IS NOT NULL AND e.published_on >= ? AND e.published_on <= ?)
                    OR (e.published_on IS NULL AND CAST(e.first_seen_at AS date) >= ? AND CAST(e.first_seen_at AS date) <= ?)
                    OR (e.edited_at IS NOT NULL AND CAST(e.edited_at AS date) >= ? AND CAST(e.edited_at AS date) <= ?)
               )',
            $args
        );
        $counts = $this->db->fetchAll(
            'SELECT s.product, COUNT(*) AS n FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ?
               AND (? = 0 OR s.product = ?)
               AND (
                    (e.published_on IS NOT NULL AND e.published_on >= ? AND e.published_on <= ?)
                    OR (e.published_on IS NULL AND CAST(e.first_seen_at AS date) >= ? AND CAST(e.first_seen_at AS date) <= ?)
                    OR (e.edited_at IS NOT NULL AND CAST(e.edited_at AS date) >= ? AND CAST(e.edited_at AS date) <= ?)
               )
             GROUP BY s.product',
            $args
        );
        $found = $this->db->fetchAll(
            'SELECT e.id, e.title, e.published_on, e.url, e.summary, e.brief, e.first_seen_at, e.edited_at, e.version_text,
                    s.product, s.label AS source_label, s.id AS source_id,
                    (SELECT COUNT(DISTINCT e2.source_id) FROM digest_entries e2 WHERE e2.user_id = e.user_id AND e2.url = e.url) AS source_count,
                    (SELECT COALESCE(json_agg(json_build_object(\'id\', t.id, \'label\', t.label, \'slug\', t.slug) ORDER BY t.label), \'[]\'::json)
                     FROM digest_entry_topics et
                     JOIN digest_topics t ON t.id = et.topic_id
                     WHERE et.entry_id = e.id) AS topics
             FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ?
               AND (? = 0 OR s.product = ?)
               AND (
                    (e.published_on IS NOT NULL AND e.published_on >= ? AND e.published_on <= ?)
                    OR (e.published_on IS NULL AND CAST(e.first_seen_at AS date) >= ? AND CAST(e.first_seen_at AS date) <= ?)
                    OR (e.edited_at IS NOT NULL AND CAST(e.edited_at AS date) >= ? AND CAST(e.edited_at AS date) <= ?)
               )
             ORDER BY s.product, COALESCE(e.published_on, CAST(e.first_seen_at AS date)) DESC, e.id DESC
             LIMIT ?',
            array_merge($args, [self::COMPARE_LIMIT])
        );
        $byProduct = [];
        foreach ($counts as $row) {
            $byProduct[(string) $row['product']] = (int) $row['n'];
        }
        $cursor = $this->cursor($userId);
        $grouped = [];
        foreach ($found as $row) {
            $key = (string) $row['product'];
            $grouped[$key][] = $this->presentItem($row, $cursor);
        }
        $groups = [];
        foreach (DigestCatalog::PRODUCTS as $id => $label) {
            if (!isset($byProduct[$id])) {
                continue;
            }
            $groups[] = [
                'product' => $id,
                'product_label' => $label,
                'count' => $byProduct[$id],
                'items' => $grouped[$id] ?? [],
            ];
        }
        return [
            'mode' => 'date',
            'from' => $from,
            'to' => $to,
            'product' => $product,
            'count' => $total,
            'truncated' => $total > self::COMPARE_LIMIT,
            'groups' => $groups,
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    private function compareVersions(int $userId, array $filter): array
    {
        if (!$this->filled($filter['product'] ?? null)) {
            throw ValidationException::withFields(['product' => 'Choose a product for a version compare.']);
        }
        if (!$this->filled($filter['from_version'] ?? null) || !$this->filled($filter['to_version'] ?? null)) {
            throw ValidationException::withFields(['from_version' => 'Choose a from and to version.']);
        }
        $product = DigestCatalog::product((string) $filter['product']);
        $from = DigestVersion::require((string) $filter['from_version'], 'from_version');
        $to = DigestVersion::require((string) $filter['to_version'], 'to_version');
        if ($from['version_sort'] > $to['version_sort']) {
            [$from, $to] = [$to, $from];
        }
        $args = [$userId, $product, $from['version_sort'], $to['version_sort']];
        $total = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ? AND s.product = ? AND e.version_sort IS NOT NULL
               AND e.version_sort >= ? AND e.version_sort <= ?',
            $args
        );
        $found = $this->db->fetchAll(
            'SELECT e.id, e.title, e.published_on, e.url, e.summary, e.brief, e.first_seen_at, e.edited_at, e.version_text,
                    s.product, s.label AS source_label, s.id AS source_id,
                    (SELECT COUNT(DISTINCT e2.source_id) FROM digest_entries e2 WHERE e2.user_id = e.user_id AND e2.url = e.url) AS source_count,
                    (SELECT COALESCE(json_agg(json_build_object(\'id\', t.id, \'label\', t.label, \'slug\', t.slug) ORDER BY t.label), \'[]\'::json)
                     FROM digest_entry_topics et
                     JOIN digest_topics t ON t.id = et.topic_id
                     WHERE et.entry_id = e.id) AS topics
             FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ? AND s.product = ? AND e.version_sort IS NOT NULL
               AND e.version_sort >= ? AND e.version_sort <= ?
             ORDER BY e.version_sort ASC, e.published_on ASC NULLS LAST, e.id ASC
             LIMIT ?',
            array_merge($args, [self::COMPARE_LIMIT])
        );
        $cursor = $this->cursor($userId);
        $items = [];
        foreach ($found as $row) {
            $items[] = $this->presentItem($row, $cursor);
        }
        return [
            'mode' => 'version',
            'product' => $product,
            'product_label' => DigestCatalog::PRODUCTS[$product],
            'from_version' => $from['version_text'],
            'to_version' => $to['version_text'],
            'count' => $total,
            'truncated' => $total > self::COMPARE_LIMIT,
            'items' => $items,
        ];
    }

    /**
     * @return array{captured_on: string, body: string}|null
     */
    private function snapshotOn(int $userId, int $sourceId, string $day): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT captured_on, body FROM digest_snapshots
             WHERE user_id = ? AND source_id = ? AND captured_on <= ?
             ORDER BY captured_on DESC LIMIT 1',
            [$userId, $sourceId, $day]
        );
        if ($row === null) {
            return null;
        }
        return [
            'captured_on' => (string) $row['captured_on'],
            'body' => (string) $row['body'],
        ];
    }

    private function retentionDays(int $userId): int
    {
        $value = $this->db->fetchScalar(
            'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$userId, self::RETENTION_KEY]
        );
        if ($value === null || !ctype_digit((string) $value)) {
            return self::RETENTION_DEFAULT;
        }
        $days = (int) $value;
        if ($days < 1 || $days > 3650) {
            return self::RETENTION_DEFAULT;
        }
        return $days;
    }

    private function day(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) !== 1
            || !checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw ValidationException::withFields([$field => 'Use a real date (YYYY-MM-DD).']);
        }
        return $value;
    }

    private function filled(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private function topicFilter(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return DigestTopics::name((string) $value)['slug'];
    }

    /**
     * @return list<array{id: int, label: string, slug: string}>
     */
    private function topicChips(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.id, t.label, t.slug
             FROM digest_topics t
             WHERE t.user_id = ?
               AND EXISTS (SELECT 1 FROM digest_entry_topics et WHERE et.topic_id = t.id)
             ORDER BY t.label, t.id',
            [$userId]
        );
        return $this->topicRows($rows);
    }

    /**
     * @return list<array{id: int, label: string, slug: string}>
     */
    private function entryTopics(int $userId, int $entryId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT t.id, t.label, t.slug
             FROM digest_entry_topics et
             JOIN digest_topics t ON t.id = et.topic_id
             WHERE et.user_id = ? AND et.entry_id = ?
             ORDER BY t.label, t.id',
            [$userId, $entryId]
        );
        return $this->topicRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{id: int, label: string, slug: string}>
     */
    private function topicRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['label'],
                'slug' => (string) $row['slug'],
            ];
        }
        return $out;
    }

    /**
     * @return list<array{id: int, label: string, slug: string}>
     */
    private function decodeTopics(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
        } else {
            $decoded = $value;
        }
        if (!is_array($decoded)) {
            return [];
        }
        $rows = [];
        foreach ($decoded as $topic) {
            if (!is_array($topic) || !isset($topic['id'], $topic['label'], $topic['slug'])) {
                continue;
            }
            $rows[] = $topic;
        }
        return $this->topicRows($rows);
    }

    private function dropUnusedTopics(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM digest_topics t
             WHERE t.user_id = ?
               AND NOT EXISTS (SELECT 1 FROM digest_entry_topics et WHERE et.topic_id = t.id)',
            [$userId]
        );
    }

    private function ownedEntry(int $userId, int $entryId): void
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM digest_entries WHERE user_id = ? AND id = ?',
            [$userId, $entryId]
        );
        if ($row === null) {
            throw new NotFoundException('Entry not found.');
        }
    }
}
