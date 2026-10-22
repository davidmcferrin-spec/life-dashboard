<?php

declare(strict_types=1);

namespace Dashboard\Digest;

use Dashboard\DB\Database;
use Dashboard\Deploy\DeployService;
use Dashboard\Settings\ServiceControl;
use Dashboard\Spotify\SpotifyAccess;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

final class DigestService
{
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
        $product = $this->productFilter($filter['product'] ?? null);
        $since = $this->sinceFilter($filter['since'] ?? null);
        $unread = !empty($filter['unread']);
        $query = $this->search($filter['q'] ?? null);
        $limit = $this->limit($filter['limit'] ?? 50);
        $cursor = $this->cursor($userId);
        $rows = [];
        $found = $this->db->fetchAll(
            'SELECT e.id, e.title, e.published_on, e.url, e.summary, e.first_seen_at, e.edited_at,
                    s.product, s.label AS source_label, s.id AS source_id
             FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ?
               AND (? = 0 OR s.product = ?)
               AND (? = 0 OR (e.published_on IS NOT NULL AND e.published_on >= ?) OR (e.published_on IS NULL AND e.first_seen_at >= ?))
               AND (? = 0 OR e.title ILIKE ? OR e.summary ILIKE ?)
               AND (? = 0 OR e.first_seen_at > ? OR (e.edited_at IS NOT NULL AND e.edited_at > ?))
             ORDER BY COALESCE(e.published_on, CAST(e.first_seen_at AS date)) DESC, e.first_seen_at DESC, e.id DESC
             LIMIT ?',
            [
                $userId,
                $product === null ? 0 : 1, $product ?? '',
                $since === null ? 0 : 1, $since ?? '1970-01-01', ($since ?? '1970-01-01') . ' 00:00:00',
                $query === null ? 0 : 1, $query ?? '%', $query ?? '%',
                ($unread && $cursor !== null) ? 1 : 0, $cursor ?? '1970-01-01 00:00:00', $cursor ?? '1970-01-01 00:00:00',
                $limit,
            ]
        );
        foreach ($found as $row) {
            $rows[] = $this->presentItem($row, $cursor);
        }
        return [
            'items'           => $rows,
            'new_count'       => $this->newCount($userId, $product),
            'read_through_at' => $this->stamp($cursor),
            'products'        => $this->products(),
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
        return ['sources' => $sources, 'products' => $this->products()];
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

    private function newCount(int $userId, ?string $product = null): int
    {
        $cursor = $this->cursor($userId);
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM digest_entries e
             JOIN digest_sources s ON s.id = e.source_id
             WHERE e.user_id = ?
               AND (? = 0 OR s.product = ?)
               AND (? = 0 OR e.first_seen_at > ? OR (e.edited_at IS NOT NULL AND e.edited_at > ?))',
            [
                $userId,
                $product === null ? 0 : 1, $product ?? '',
                $cursor === null ? 0 : 1, $cursor ?? '1970-01-01 00:00:00', $cursor ?? '1970-01-01 00:00:00',
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
        return [
            'id'            => (int) $row['id'],
            'title'         => (string) $row['title'],
            'published_on'  => $row['published_on'] !== null ? (string) $row['published_on'] : null,
            'url'           => (string) $row['url'],
            'summary'       => (string) $row['summary'],
            'first_seen_at' => $this->stamp($first),
            'edited_at'     => $this->stamp($edited),
            'product'       => $product,
            'product_label' => DigestCatalog::PRODUCTS[$product] ?? $product,
            'source_label'  => (string) $row['source_label'],
            'source_id'     => (int) $row['source_id'],
            'is_new'        => $isNew,
            'edited'        => $edited !== null,
        ];
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

    private function stamp(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = str_replace(' ', 'T', $value);
        return str_ends_with($value, 'Z') ? $value : $value . 'Z';
    }
}
