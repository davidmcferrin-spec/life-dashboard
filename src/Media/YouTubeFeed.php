<?php

declare(strict_types=1);

namespace Dashboard\Media;

use Dashboard\Feed\RssFeedParser;

/**
 * Public channel Atom feed. Handles are resolved from the channel page, then the feed is fetched.
 */
final class YouTubeFeed
{
    private const HOSTS = ['www.youtube.com', 'youtube.com'];

    public function __construct(private readonly MediaHttp $http) {}

    /**
     * @param array{key: string, label: string, channel_id: ?string, handle: ?string, page: ?string} $source
     * @return array{remote_id: string, items: list<array{id: string, title: string, body: string, url: string, published_at: ?string}>}
     */
    public function fetch(array $source): array
    {
        $channelId = $source['channel_id'];
        if ($channelId === null) {
            $page = $source['page'] ?? ('https://www.youtube.com/@' . rawurlencode((string) $source['handle']));
            $channelId = self::channelIdFromHtml($this->http->get($page, self::HOSTS));
            if ($channelId === null) {
                throw new \RuntimeException('That page did not include a channel id.');
            }
        }
        $xml = $this->http->get(
            'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode($channelId),
            self::HOSTS
        );
        return ['remote_id' => $channelId, 'items' => self::itemsFromXml($xml)];
    }

    public static function channelIdFromHtml(string $html): ?string
    {
        if (preg_match('/channel_id=(UC[\w-]{22})/', $html, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/"channelId":"(UC[\w-]{22})"/', $html, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/"externalId":"(UC[\w-]{22})"/', $html, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    /**
     * @return list<array{id: string, title: string, body: string, url: string, published_at: ?string}>
     */
    public static function itemsFromXml(string $xml): array
    {
        if (!str_contains($xml, '<entry') && !str_contains($xml, '<item')) {
            throw new \RuntimeException('YouTube did not return a feed.');
        }
        $items = [];
        foreach (array_slice(RssFeedParser::parse($xml), 0, 15) as $row) {
            $id = self::videoId($row['url']);
            if ($id === null) {
                continue;
            }
            $watch = str_contains($row['url'], '/shorts/')
                ? 'https://www.youtube.com/shorts/' . $id
                : 'https://www.youtube.com/watch?v=' . $id;
            $items[] = [
                'id'           => $id,
                'title'        => $row['title'],
                'body'         => (string) ($row['description'] ?? ''),
                'url'          => $watch,
                'published_at' => $row['published_at'],
            ];
        }
        return $items;
    }

    public static function videoId(string $url): ?string
    {
        if (preg_match('~(?:v=|youtu\.be/|shorts/|embed/)([\w-]{11})~', $url, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    public static function thumb(string $videoId): string
    {
        return 'https://i.ytimg.com/vi/' . $videoId . '/hqdefault.jpg';
    }
}
