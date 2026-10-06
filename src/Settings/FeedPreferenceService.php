<?php

declare(strict_types=1);

namespace Dashboard\Settings;

use Dashboard\DB\Database;
use Dashboard\Feed\FeedSourceService;

final class FeedPreferenceService
{
    public function __construct(
        private readonly Database $db,
        private readonly FeedSourceService $feedSources,
    ) {}

    public static function fromDatabase(Database $db): self
    {
        return new self($db, FeedSourceService::fromDatabase($db));
    }

    /**
     * Built-in (system) feed sources with dashboard pin state.
     *
     * @return list<array<string, mixed>>
     */
    public function listSystemWithState(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT fs.id, fs.slug, fs.name, fs.category, fs.is_default,
                    COALESCE(uf.enabled, 0) AS enabled
             FROM feed_sources fs
             LEFT JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
             WHERE fs.user_id IS NULL
             ORDER BY fs.sort_order ASC, fs.name ASC',
            [$userId]
        );

        return array_map(fn (array $row): array => $this->mapRow($row, false), $rows);
    }

    /**
     * All sources the user may toggle (system + own custom).
     *
     * @return list<array<string, mixed>>
     */
    public function listWithState(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT fs.id, fs.slug, fs.name, fs.category, fs.is_default, fs.user_id,
                    COALESCE(uf.enabled, 0) AS enabled
             FROM feed_sources fs
             LEFT JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
             WHERE fs.user_id IS NULL OR fs.user_id = ?
             ORDER BY fs.user_id IS NOT NULL ASC, fs.sort_order ASC, fs.name ASC',
            [$userId, $userId]
        );

        return array_map(
            fn (array $row): array => $this->mapRow($row, $row['user_id'] !== null),
            $rows,
        );
    }

    public function hasDashboardPins(int $userId): bool
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*)
             FROM user_feeds uf
             INNER JOIN feed_sources fs ON fs.id = uf.source_id
             WHERE uf.user_id = ?
               AND uf.enabled = 1
               AND (fs.user_id IS NULL OR fs.user_id = ?)',
            [$userId, $userId]
        ) > 0;
    }

    /**
     * Seed dashboard pins for new users: built-in is_default sources + any custom without a row.
     */
    public function seedDashboardPins(int $userId): void
    {
        $defaults = $this->db->fetchAll(
            'SELECT fs.id
             FROM feed_sources fs
             LEFT JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
             WHERE fs.user_id IS NULL
               AND fs.is_default = 1
               AND uf.id IS NULL',
            [$userId]
        );

        foreach ($defaults as $row) {
            $this->db->execute(
                'INSERT INTO user_feeds (user_id, source_id, enabled) VALUES (?, ?, 1)',
                [$userId, (int) $row['id']]
            );
        }
    }

    /**
     * One-time migration: preserve prior implicit enables as explicit dashboard pins.
     */
    public function migrateLegacyDashboardPins(int $userId): void
    {
        $builtIn = $this->db->fetchAll(
            'SELECT fs.id, fs.is_default
             FROM feed_sources fs
             LEFT JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
             WHERE fs.user_id IS NULL AND uf.id IS NULL',
            [$userId]
        );

        foreach ($builtIn as $row) {
            if ((int) $row['is_default'] !== 1) {
                continue;
            }
            $this->db->execute(
                'INSERT INTO user_feeds (user_id, source_id, enabled) VALUES (?, ?, 1)',
                [$userId, (int) $row['id']]
            );
        }

        $custom = $this->db->fetchAll(
            'SELECT fs.id
             FROM feed_sources fs
             LEFT JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
             WHERE fs.user_id = ? AND uf.id IS NULL',
            [$userId, $userId]
        );

        foreach ($custom as $row) {
            $this->db->execute(
                'INSERT INTO user_feeds (user_id, source_id, enabled) VALUES (?, ?, 1)',
                [$userId, (int) $row['id']]
            );
        }
    }

    /**
     * @param array<int, bool> $enabledBySourceId source_id => pinned on dashboard
     */
    public function save(int $userId, array $enabledBySourceId): void
    {
        foreach ($enabledBySourceId as $sourceId => $enabled) {
            $sourceId = (int) $sourceId;

            if (!$this->feedSources->userOwnsSource($userId, $sourceId)) {
                continue;
            }

            $row = $this->db->fetchOne(
                'SELECT id FROM user_feeds WHERE user_id = ? AND source_id = ?',
                [$userId, $sourceId]
            );

            if ($row !== null) {
                $this->db->execute(
                    'UPDATE user_feeds SET enabled = ? WHERE user_id = ? AND source_id = ?',
                    [$enabled ? 1 : 0, $userId, $sourceId]
                );
            } else {
                $this->db->execute(
                    'INSERT INTO user_feeds (user_id, source_id, enabled) VALUES (?, ?, ?)',
                    [$userId, $sourceId, $enabled ? 1 : 0]
                );
            }
        }
    }

    public function feedSourceService(): FeedSourceService
    {
        return $this->feedSources;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRow(array $row, bool $isCustom): array
    {
        return [
            'id'        => (int) $row['id'],
            'slug'      => (string) $row['slug'],
            'name'      => (string) $row['name'],
            'category'  => (string) $row['category'],
            'enabled'   => (bool) $row['enabled'],
            'is_custom' => $isCustom,
        ];
    }
}
