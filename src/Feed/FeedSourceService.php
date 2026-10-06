<?php

declare(strict_types=1);

namespace Dashboard\Feed;

use Dashboard\Config;
use Dashboard\DB\Database;

final class FeedSourceService
{
    public const MAX_CUSTOM_FEEDS = 10;

    public function __construct(private readonly Database $db) {}

    public static function fromDatabase(Database $db): self
    {
        return new self($db);
    }

    public function maxCustomFeeds(): int
    {
        return Config::int('RSS_MAX_CUSTOM_FEEDS', self::MAX_CUSTOM_FEEDS);
    }

    public function countCustom(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM feed_sources WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCustomForUser(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT fs.id, fs.slug, fs.name, fs.url, fs.category,
                    COALESCE(uf.enabled, 0) AS enabled
             FROM feed_sources fs
             LEFT JOIN user_feeds uf ON uf.source_id = fs.id AND uf.user_id = ?
             WHERE fs.user_id = ?
             ORDER BY fs.name ASC',
            [$userId, $userId]
        );

        return array_map(static fn (array $row): array => [
            'id'       => (int) $row['id'],
            'slug'     => (string) $row['slug'],
            'name'     => (string) $row['name'],
            'url'      => (string) $row['url'],
            'category' => (string) $row['category'],
            'enabled'  => (bool) $row['enabled'],
            'is_custom'=> true,
        ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $name, string $url): array
    {
        $name = trim($name);
        $url  = trim($url);

        if ($name === '' || mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('Feed name is required (max 128 characters).');
        }

        if ($this->countCustom($userId) >= $this->maxCustomFeeds()) {
            throw new \InvalidArgumentException(
                'Maximum of ' . $this->maxCustomFeeds() . ' custom feeds reached.',
            );
        }

        $this->assertSafeFeedUrl($url);

        $xml = RssFeedParser::fetch($url);

        if ($xml === null) {
            throw new \InvalidArgumentException('Could not fetch feed URL. Check the address and try again.');
        }

        $items = RssFeedParser::parse($xml);

        if ($items === []) {
            throw new \InvalidArgumentException('URL does not appear to be a valid RSS or Atom feed.');
        }

        $slug = $this->generateSlug($userId);
        $maxItems = Config::int('RSS_ITEMS_PER_FEED', 10);

        return $this->db->transaction(function (Database $db) use (
            $userId,
            $name,
            $url,
            $slug,
            $items,
            $maxItems,
        ): array {
            $db->execute(
                'INSERT INTO feed_sources (slug, name, url, category, is_default, sort_order, user_id)
                 VALUES (?, ?, ?, ?, 0, 1000, ?)',
                [$slug, $name, $url, 'custom', $userId]
            );

            $sourceId = (int) $db->lastInsertId();

            $db->execute(
                'INSERT INTO user_feeds (user_id, source_id, enabled) VALUES (?, ?, 1)',
                [$userId, $sourceId]
            );

            RssFeedParser::cacheItemsForSource($db, $sourceId, $items, $maxItems);
            RssFeedParser::pruneSource($db, $sourceId, $maxItems * 3);

            return [
                'id'       => $sourceId,
                'slug'     => $slug,
                'name'     => $name,
                'url'      => $url,
                'category' => 'custom',
                'enabled'  => true,
                'is_custom'=> true,
            ];
        });
    }

    public function delete(int $userId, int $sourceId): void
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM feed_sources WHERE id = ? AND user_id = ?',
            [$sourceId, $userId]
        );

        if ($row === null) {
            throw new \InvalidArgumentException('Feed not found or not owned by you.');
        }

        $this->db->execute('DELETE FROM feed_sources WHERE id = ? AND user_id = ?', [$sourceId, $userId]);
    }

    public function userOwnsSource(int $userId, int $sourceId): bool
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM feed_sources WHERE id = ? AND (user_id IS NULL OR user_id = ?)',
            [$sourceId, $userId]
        );

        return $row !== null;
    }

    public function assertSafeFeedUrl(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false) {
            throw new \InvalidArgumentException('Invalid feed URL.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Feed URL must use http or https.');
        }

        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($host === '') {
            throw new \InvalidArgumentException('Feed URL must include a host.');
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new \InvalidArgumentException('Feed URL host is not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                throw new \InvalidArgumentException('Feed URL host is not allowed.');
            }

            return;
        }

        $records = @dns_get_record($host, DNS_A + DNS_AAAA);

        if ($records === false || $records === []) {
            $resolved = @gethostbyname($host);

            if ($resolved !== $host && $this->isBlockedIp($resolved)) {
                throw new \InvalidArgumentException('Feed URL host is not allowed.');
            }

            return;
        }

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

            if ($ip !== '' && $this->isBlockedIp($ip)) {
                throw new \InvalidArgumentException('Feed URL host is not allowed.');
            }
        }
    }

    private function isBlockedIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);

            if ($long === false) {
                return true;
            }

            $blocked = [
                ['0.0.0.0', '127.255.255.255'],
                ['10.0.0.0', '10.255.255.255'],
                ['172.16.0.0', '172.31.255.255'],
                ['192.168.0.0', '192.168.255.255'],
                ['169.254.0.0', '169.254.255.255'],
            ];

            foreach ($blocked as [$start, $end]) {
                if ($long >= ip2long($start) && $long <= ip2long($end)) {
                    return true;
                }
            }

            return false;
        }

        $lower = strtolower($ip);

        return $lower === '::1'
            || str_starts_with($lower, 'fc')
            || str_starts_with($lower, 'fd')
            || str_starts_with($lower, 'fe80:');
    }

    private function generateSlug(int $userId): string
    {
        do {
            $slug = 'u' . $userId . '-' . bin2hex(random_bytes(4));
            $exists = $this->db->fetchOne('SELECT id FROM feed_sources WHERE slug = ?', [$slug]);
        } while ($exists !== null);

        return $slug;
    }
}
