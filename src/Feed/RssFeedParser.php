<?php

declare(strict_types=1);

namespace Dashboard\Feed;

use Dashboard\DB\Database;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Shared RSS/Atom fetch, parse, and feed_cache helpers (cron + on-demand).
 */
final class RssFeedParser
{
    public static function fetch(string $url): ?string
    {
        $ctx = stream_context_create([
            'http' => [
                'timeout'         => 10,
                'user_agent'      => 'PersonalDashboard/1.0 (RSS Reader)',
                'follow_location' => true,
                'max_redirects'   => 3,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $ctx);

        return $body !== false && strlen($body) > 100 ? $body : null;
    }

    /**
     * @return list<array{title: string, url: string, description: string|null, published_at: string|null}>
     */
    public static function parse(string $xml): array
    {
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $items = [];

        $isAtom = $dom->getElementsByTagNameNS('http://www.w3.org/2005/Atom', 'feed')->length > 0
            || $dom->documentElement?->nodeName === 'feed';

        if ($isAtom) {
            $xpath->registerNamespace('atom', 'http://www.w3.org/2005/Atom');
            $entries = $xpath->query('//atom:entry') ?? $xpath->query('//entry');

            foreach ($entries as $entry) {
                $title       = self::nodeText($xpath, 'atom:title|title', $entry);
                $link        = self::nodeAttr($xpath, 'atom:link|link', $entry, 'href')
                    ?? self::nodeText($xpath, 'atom:link|link', $entry);
                $description = self::nodeText($xpath, 'atom:summary|atom:content|summary|content', $entry);
                $pubDate     = self::nodeText($xpath, 'atom:published|atom:updated|published|updated', $entry);

                if ($title && $link) {
                    $items[] = [
                        'title'        => trim(html_entity_decode(strip_tags($title), ENT_QUOTES)),
                        'url'          => trim($link),
                        'description'  => $description ? trim(strip_tags($description)) : null,
                        'published_at' => $pubDate ? self::normaliseDate($pubDate) : null,
                    ];
                }
            }
        } else {
            $entries = $xpath->query('//item');

            foreach ($entries as $entry) {
                $title       = self::nodeText($xpath, 'title', $entry);
                $link        = self::nodeText($xpath, 'link', $entry);
                $description = self::nodeText($xpath, 'description', $entry);
                $pubDate     = self::nodeText($xpath, 'pubDate', $entry);

                if ($title && $link) {
                    $items[] = [
                        'title'        => trim(html_entity_decode(strip_tags($title), ENT_QUOTES)),
                        'url'          => trim($link),
                        'description'  => $description ? trim(mb_substr(strip_tags($description), 0, 500)) : null,
                        'published_at' => $pubDate ? self::normaliseDate($pubDate) : null,
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * Insert new articles into feed_cache (dedupe by source_id + url).
     *
     * @param list<array{title: string, url: string, description: string|null, published_at: string|null}> $items
     */
    public static function cacheItemsForSource(Database $db, int $sourceId, array $items, int $maxItems): int
    {
        $items    = array_slice($items, 0, $maxItems);
        $inserted = 0;

        foreach ($items as $item) {
            $existing = $db->fetchOne(
                'SELECT id FROM feed_cache WHERE source_id = ? AND url = ?',
                [$sourceId, $item['url']]
            );

            if ($existing !== null) {
                continue;
            }

            $db->execute(
                'INSERT INTO feed_cache (source_id, title, url, description, published_at)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $sourceId,
                    mb_substr($item['title'], 0, 512),
                    mb_substr($item['url'], 0, 2048),
                    $item['description'] ? mb_substr($item['description'], 0, 2000) : null,
                    $item['published_at'],
                ]
            );
            $inserted++;
        }

        return $inserted;
    }

    public static function pruneSource(Database $db, int $sourceId, int $keepPerSource): void
    {
        $cutoff = $db->fetchOne(
            'SELECT id FROM feed_cache WHERE source_id = ?
             ORDER BY COALESCE(published_at, fetched_at) DESC
             LIMIT 1 OFFSET ?',
            [$sourceId, $keepPerSource - 1]
        );

        if ($cutoff === null) {
            return;
        }

        $db->execute(
            "DELETE FROM feed_cache
             WHERE source_id = ?
               AND COALESCE(published_at, fetched_at) < (
                   SELECT COALESCE(published_at, fetched_at) FROM feed_cache WHERE id = ?
               )",
            [$sourceId, (int) $cutoff['id']]
        );
    }

    public static function pruneAllSources(Database $db, int $keepPerSource): void
    {
        $sources = $db->fetchAll('SELECT id FROM feed_sources');

        foreach ($sources as $source) {
            self::pruneSource($db, (int) $source['id'], $keepPerSource);
        }
    }

    /**
     * Delete cached articles older than $maxAgeDays (by published_at, else fetched_at).
     */
    public static function pruneOlderThan(Database $db, int $maxAgeDays): int
    {
        if ($maxAgeDays <= 0) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$maxAgeDays} days"));

        return $db->execute(
            'DELETE FROM feed_cache WHERE COALESCE(published_at, fetched_at) < ?',
            [$cutoff]
        );
    }

    private static function nodeText(DOMXPath $xpath, string $query, DOMNode $ctx): ?string
    {
        $result = $xpath->query($query, $ctx);

        if ($result === false || $result->length === 0) {
            return null;
        }

        return $result->item(0)?->textContent;
    }

    private static function nodeAttr(DOMXPath $xpath, string $query, DOMNode $ctx, string $attr): ?string
    {
        $result = $xpath->query($query, $ctx);

        if ($result === false || $result->length === 0) {
            return null;
        }

        $node = $result->item(0);

        return $node instanceof DOMElement ? ($node->getAttribute($attr) ?: null) : null;
    }

    private static function normaliseDate(string $dateStr): ?string
    {
        $ts = strtotime($dateStr);

        if ($ts === false || $ts <= 0) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
