<?php

declare(strict_types=1);

namespace Dashboard\Reddit;

/**
 * Turns Reddit's public listing JSON into the rows we store.
 * No HTTP here — the client and the tests both call this.
 */
final class RedditListing
{
    /**
     * @return list<array{id: string, subreddit: string, title: string, author: string, score: int, num_comments: int, url: string, permalink: string, created_utc: string, flair: ?string, selftext: string}>
     */
    public static function posts(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Reddit did not return JSON.');
        }
        $children = $data['data']['children'] ?? null;
        if (!is_array($children)) {
            throw new \RuntimeException('Reddit did not return a listing.');
        }

        $out = [];
        foreach ($children as $child) {
            $post = is_array($child) ? ($child['data'] ?? null) : null;
            if (!is_array($post)) {
                continue;
            }
            $row = self::post($post);
            if ($row !== null) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Top-level comments from a comments JSON array. Deleted bodies are skipped.
     *
     * @return list<array{id: string, author: string, score: int, body: string, created_utc: string}>
     */
    public static function comments(string $json, int $limit = 20): array
    {
        $data = json_decode($json, true);
        $children = is_array($data) ? ($data[1]['data']['children'] ?? null) : null;
        if (!is_array($children)) {
            throw new \RuntimeException('Reddit did not return comments.');
        }

        $limit = max(1, min(40, $limit));
        $out = [];
        foreach ($children as $child) {
            if (!is_array($child) || ($child['kind'] ?? '') !== 't1' || !is_array($child['data'] ?? null)) {
                continue;
            }
            $row = self::comment($child['data']);
            if ($row === null) {
                continue;
            }
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $post
     * @return array{id: string, subreddit: string, title: string, author: string, score: int, num_comments: int, url: string, permalink: string, created_utc: string, flair: ?string, selftext: string}|null
     */
    private static function post(array $post): ?array
    {
        $id = strtolower((string) ($post['id'] ?? ''));
        $permalink = (string) ($post['permalink'] ?? '');
        $created = (int) ($post['created_utc'] ?? 0);
        $title = self::plain((string) ($post['title'] ?? ''), 300);
        if (preg_match('/^[a-z0-9]{3,12}$/', $id) !== 1 || !str_starts_with($permalink, '/r/') || $created < 1_000_000_000 || $title === '') {
            return null;
        }

        $url = (string) ($post['url'] ?? '');
        if (!str_starts_with($url, 'https://')) {
            $url = 'https://www.reddit.com' . $permalink;
        }
        $flair = $post['link_flair_text'] ?? null;
        $flair = is_string($flair) ? self::plain($flair, 80) : '';

        return [
            'id'           => $id,
            'subreddit'    => strtolower((string) ($post['subreddit'] ?? '')),
            'title'        => $title,
            'author'       => self::plain((string) ($post['author'] ?? ''), 32),
            'score'        => (int) ($post['score'] ?? 0),
            'num_comments' => max(0, (int) ($post['num_comments'] ?? 0)),
            'url'          => $url,
            'permalink'    => 'https://www.reddit.com' . $permalink,
            'created_utc'  => gmdate('Y-m-d H:i:s', $created),
            'flair'        => $flair !== '' ? $flair : null,
            'selftext'     => self::plain((string) ($post['selftext'] ?? ''), 500),
        ];
    }

    /**
     * @param array<string, mixed> $comment
     * @return array{id: string, author: string, score: int, body: string, created_utc: string}|null
     */
    private static function comment(array $comment): ?array
    {
        $body = self::plain((string) ($comment['body'] ?? ''), 400);
        if ($body === '' || $body === '[deleted]' || $body === '[removed]') {
            return null;
        }
        $created = (int) ($comment['created_utc'] ?? 0);
        return [
            'id'          => self::plain((string) ($comment['id'] ?? ''), 16),
            'author'      => self::plain((string) ($comment['author'] ?? ''), 32),
            'score'       => (int) ($comment['score'] ?? 0),
            'body'        => $body,
            'created_utc' => $created > 0 ? gmdate('Y-m-d H:i:s', $created) : '',
        ];
    }

    private static function plain(string $value, int $max): string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_substr($value, 0, $max);
    }
}
