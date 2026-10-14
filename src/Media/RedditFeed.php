<?php

declare(strict_types=1);

namespace Dashboard\Media;

use Dashboard\Feed\RssFeedParser;

/**
 * Public subreddit RSS. No account is required.
 */
final class RedditFeed
{
    public function __construct(private readonly MediaHttp $http) {}

    /**
     * @param array{key: string, label: string} $source
     * @return array{remote_id: ?string, items: list<array{id: string, title: string, body: string, url: string, published_at: ?string}>}
     */
    public function fetch(array $source): array
    {
        $sub = $source['key'];
        if (preg_match('/^[a-z0-9_]{2,21}$/', $sub) !== 1) {
            throw new \RuntimeException('Blocked subreddit.');
        }
        $xml = $this->http->get('https://www.reddit.com/r/' . rawurlencode($sub) . '/.rss?limit=15', ['www.reddit.com']);
        return ['remote_id' => null, 'items' => self::itemsFromXml($xml)];
    }

    /**
     * @return list<array{id: string, title: string, body: string, url: string, published_at: ?string}>
     */
    public static function itemsFromXml(string $xml): array
    {
        if (!str_contains($xml, '<entry') && !str_contains($xml, '<item')) {
            throw new \RuntimeException('Reddit did not return a feed.');
        }
        $items = [];
        foreach (array_slice(RssFeedParser::parse($xml), 0, 15) as $row) {
            $url = $row['url'];
            if (!str_starts_with($url, 'https://')) {
                continue;
            }
            $id = $url;
            if (preg_match('~/comments/([a-z0-9]+)/~i', $url, $m) === 1) {
                $id = $m[1];
            }
            $items[] = [
                'id'           => $id,
                'title'        => $row['title'],
                'body'         => (string) ($row['description'] ?? ''),
                'url'          => $url,
                'published_at' => $row['published_at'],
            ];
        }
        return $items;
    }
}
