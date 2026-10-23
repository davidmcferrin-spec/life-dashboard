<?php

declare(strict_types=1);

namespace Dashboard\Feed;

use Dashboard\DB\Database;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Pinned feeds for the top-level Feeds page, plus the system-context card.
 */
final class FeedBoard
{
    public function __construct(private readonly Database $db) {}

    public static function fromDatabase(Database $db): self
    {
        return new self($db);
    }

    /**
     * Connected means a source this account pinned, not every built-in feed.
     *
     * @return array{
     *     context: array{connected: int, last_sync: string, status: string, ready: bool},
     *     articles: list<array{title: string, url: string, source: string, category: string, at: string}>
     * }
     */
    public function forUser(int $userId): array
    {
        $connected = (int) $this->db->fetchScalar(
            'SELECT COUNT(*)
             FROM user_feeds uf
             INNER JOIN feed_sources fs ON fs.id = uf.source_id
             WHERE uf.user_id = ?
               AND uf.enabled = 1
               AND (fs.user_id IS NULL OR fs.user_id = ?)',
            [$userId, $userId]
        );
        $lastSync = $this->db->fetchScalar(
            'SELECT MAX(fc.fetched_at)
             FROM feed_cache fc
             INNER JOIN user_feeds uf ON uf.source_id = fc.source_id AND uf.user_id = ? AND uf.enabled = 1
             INNER JOIN feed_sources fs ON fs.id = fc.source_id
             WHERE fs.user_id IS NULL OR fs.user_id = ?',
            [$userId, $userId]
        );
        $rows = $this->db->fetchAll(
            'SELECT fc.title, fc.url, fc.published_at, fc.fetched_at, fs.name AS source_name, fs.category
             FROM feed_cache fc
             INNER JOIN user_feeds uf ON uf.source_id = fc.source_id AND uf.user_id = ? AND uf.enabled = 1
             INNER JOIN feed_sources fs ON fs.id = fc.source_id
             WHERE fs.user_id IS NULL OR fs.user_id = ?
             ORDER BY COALESCE(fc.published_at, fc.fetched_at) DESC
             LIMIT 12',
            [$userId, $userId]
        );

        return [
            'context' => self::context($connected, $lastSync === null ? null : (string) $lastSync),
            'articles' => self::articlesFromRows($rows),
        ];
    }

    /**
     * @return array{connected: int, last_sync: string, status: string, ready: bool}
     */
    public static function context(int $connected, ?string $lastSync, ?DateTimeImmutable $now = null): array
    {
        $connected = max(0, $connected);
        if ($connected === 0) {
            return [
                'connected' => 0,
                'last_sync' => 'Not yet',
                'status' => 'Needs setup',
                'ready' => false,
            ];
        }
        if (self::parseUtc($lastSync) === null) {
            return [
                'connected' => $connected,
                'last_sync' => 'Not yet',
                'status' => 'Waiting',
                'ready' => false,
            ];
        }

        return [
            'connected' => $connected,
            'last_sync' => self::ago($lastSync, $now),
            'status' => 'Ready',
            'ready' => true,
        ];
    }

    public static function ago(?string $utc, ?DateTimeImmutable $now = null): string
    {
        $parsed = self::parseUtc($utc);
        if ($parsed === null) {
            $raw = trim((string) $utc);
            return $raw === '' ? 'Not yet' : $raw;
        }
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $seconds = $now->getTimestamp() - $parsed->getTimestamp();
        if ($seconds < 0) {
            return $parsed->format('M j, H:i') . ' UTC';
        }
        if ($seconds < 60) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            return $minutes . ' minute' . ($minutes === 1 ? '' : 's') . ' ago';
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }

        return $parsed->format('M j, H:i') . ' UTC';
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{title: string, url: string, source: string, category: string, at: string}>
     */
    public static function articlesFromRows(array $rows): array
    {
        $articles = [];
        foreach ($rows as $row) {
            $title = trim((string) ($row['title'] ?? ''));
            $url = self::httpUrl((string) ($row['url'] ?? ''));
            if ($title === '' || $url === '') {
                continue;
            }
            $published = trim((string) ($row['published_at'] ?? ''));
            $fetched = trim((string) ($row['fetched_at'] ?? ''));
            $articles[] = [
                'title' => $title,
                'url' => $url,
                'source' => trim((string) ($row['source_name'] ?? '')),
                'category' => trim((string) ($row['category'] ?? '')),
                'at' => $published !== '' ? $published : $fetched,
            ];
        }

        return $articles;
    }

    private static function httpUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#\Ahttps?://#i', $url) !== 1) {
            return '';
        }

        return $url;
    }

    private static function parseUtc(?string $utc): ?DateTimeImmutable
    {
        $utc = trim((string) $utc);
        if ($utc === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $utc, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))) {
            return null;
        }

        return $parsed;
    }
}
