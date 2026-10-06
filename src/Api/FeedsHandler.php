<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Config;
use Dashboard\DB\Database;

/**
 * JSON API for news feeds (cron-populated feed_cache).
 *
 * scope=dashboard — sources pinned on the dashboard (user_feeds.enabled = 1)
 * scope=reader    — all built-in sources + this user's custom feeds
 */
final class FeedsHandler
{
    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        $userId = (int) $user['id'];
        $scope  = strtolower(trim((string) ($_GET['scope'] ?? 'reader')));

        if (!in_array($scope, ['dashboard', 'reader'], true)) {
            jsonResponse(['error' => 'Invalid scope. Use dashboard or reader.'], 400);
        }

        $itemsPerFeed = $scope === 'reader'
            ? Config::int('RSS_READER_ITEMS_PER_FEED', 15)
            : Config::int('RSS_ITEMS_PER_FEED', 10);

        if ($scope === 'dashboard') {
            $sources  = $this->dashboardSources($userId);
            $articles = $this->articlesForDashboard($userId, $itemsPerFeed);
        } else {
            $sources  = $this->readerSources($userId);
            $articles = $this->articlesForReader($userId, $itemsPerFeed);
        }

        $output = array_map(static fn (array $a): array => [
            'title'        => $a['title'],
            'url'          => $a['url'],
            'description'  => $a['description']
                ? mb_substr(strip_tags((string) $a['description']), 0, 200)
                : null,
            'published_at' => $a['published_at'],
            'source_slug'  => $a['source_slug'],
            'source_name'  => $a['source_name'],
            'category'     => $a['category'],
        ], $articles);

        jsonResponse([
            'scope'    => $scope,
            'articles' => $output,
            'count'    => count($output),
            'sources'  => array_map(static fn (array $s): array => [
                'slug'     => (string) $s['slug'],
                'name'     => (string) $s['name'],
                'category' => (string) $s['category'],
            ], $sources),
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dashboardSources(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT fs.slug, fs.name, fs.category
             FROM feed_sources fs
             INNER JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
             WHERE (fs.user_id IS NULL OR fs.user_id = ?)
               AND uf.enabled = 1
             ORDER BY fs.sort_order ASC, fs.name ASC',
            [$userId, $userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readerSources(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT fs.slug, fs.name, fs.category
             FROM feed_sources fs
             WHERE fs.user_id IS NULL OR fs.user_id = ?
             ORDER BY fs.user_id IS NOT NULL ASC, fs.sort_order ASC, fs.name ASC',
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articlesForDashboard(int $userId, int $itemsPerFeed): array
    {
        return $this->db->fetchAll(
            "WITH pinned_sources AS (
                SELECT fs.id, fs.slug, fs.name, fs.category
                FROM feed_sources fs
                INNER JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
                WHERE (fs.user_id IS NULL OR fs.user_id = ?)
                  AND uf.enabled = 1
            ),
            ranked AS (
                SELECT
                    fc.title,
                    fc.url,
                    fc.description,
                    fc.published_at,
                    fc.fetched_at,
                    ps.slug AS source_slug,
                    ps.name AS source_name,
                    ps.category,
                    ROW_NUMBER() OVER (
                        PARTITION BY fc.source_id
                        ORDER BY COALESCE(fc.published_at, fc.fetched_at) DESC
                    ) AS rn
                FROM feed_cache fc
                INNER JOIN pinned_sources ps ON ps.id = fc.source_id
            )
            SELECT
                title,
                url,
                description,
                published_at,
                source_slug,
                source_name,
                category
            FROM ranked
            WHERE rn <= ?
            ORDER BY COALESCE(published_at, fetched_at) DESC",
            [$userId, $userId, $itemsPerFeed]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function articlesForReader(int $userId, int $itemsPerFeed): array
    {
        return $this->db->fetchAll(
            "WITH reader_sources AS (
                SELECT fs.id, fs.slug, fs.name, fs.category
                FROM feed_sources fs
                WHERE fs.user_id IS NULL OR fs.user_id = ?
            ),
            ranked AS (
                SELECT
                    fc.title,
                    fc.url,
                    fc.description,
                    fc.published_at,
                    fc.fetched_at,
                    rs.slug AS source_slug,
                    rs.name AS source_name,
                    rs.category,
                    ROW_NUMBER() OVER (
                        PARTITION BY fc.source_id
                        ORDER BY COALESCE(fc.published_at, fc.fetched_at) DESC
                    ) AS rn
                FROM feed_cache fc
                INNER JOIN reader_sources rs ON rs.id = fc.source_id
            )
            SELECT
                title,
                url,
                description,
                published_at,
                source_slug,
                source_name,
                category
            FROM ranked
            WHERE rn <= ?
            ORDER BY COALESCE(published_at, fetched_at) DESC",
            [$userId, $itemsPerFeed]
        );
    }
}
