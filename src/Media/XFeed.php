<?php

declare(strict_types=1);

namespace Dashboard\Media;

use Dashboard\Config;

/**
 * Recent posts for a named account through the X API.
 * X does not publish a public feed, so this stays empty until X_BEARER_TOKEN is set.
 */
final class XFeed
{
    public function __construct(private readonly MediaHttp $http) {}

    /**
     * @param array{key: string, label: string, handle: ?string, remote_id?: ?string} $source
     * @return array{remote_id: string, items: list<array{id: string, title: string, body: string, url: string, published_at: ?string}>}
     */
    public function fetch(array $source): array
    {
        $token = Config::maybe('X_BEARER_TOKEN');
        if ($token === null) {
            throw new \RuntimeException('Set X_BEARER_TOKEN on the server. X does not publish a public feed.');
        }
        $handle = (string) ($source['handle'] ?? $source['key']);
        if (preg_match('/^[A-Za-z0-9_]{1,15}$/', $handle) !== 1) {
            throw new \RuntimeException('Blocked account.');
        }
        $headers = ['Authorization' => 'Bearer ' . $token];
        $userId = $source['remote_id'] ?? null;
        if (!is_string($userId) || preg_match('/^\d{1,32}$/', $userId) !== 1) {
            $lookup = $this->http->get(
                'https://api.x.com/2/users/by/username/' . rawurlencode($handle),
                ['api.x.com'],
                $headers
            );
            $user = json_decode($lookup, true);
            $userId = is_array($user) ? (string) ($user['data']['id'] ?? '') : '';
            if (preg_match('/^\d{1,32}$/', $userId) !== 1) {
                throw new \RuntimeException('X did not return that account.');
            }
        }
        $tweets = $this->http->get(
            'https://api.x.com/2/users/' . $userId . '/tweets?max_results=10&tweet.fields=created_at,text&exclude=retweets,replies',
            ['api.x.com'],
            $headers
        );
        $payload = json_decode($tweets, true);
        if (!is_array($payload)) {
            throw new \RuntimeException('X did not return posts.');
        }
        return ['remote_id' => $userId, 'items' => self::itemsFromPayload($payload, $handle)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{id: string, title: string, body: string, url: string, published_at: ?string}>
     */
    public static function itemsFromPayload(array $payload, string $handle): array
    {
        $rows = $payload['data'] ?? [];
        if (!is_array($rows)) {
            return [];
        }
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $text = trim((string) ($row['text'] ?? ''));
            if (preg_match('/^\d{1,32}$/', $id) !== 1 || $text === '') {
                continue;
            }
            $published = null;
            $created = (string) ($row['created_at'] ?? '');
            if ($created !== '') {
                $ts = strtotime($created);
                $published = $ts !== false ? gmdate('Y-m-d H:i:s', $ts) : null;
            }
            $items[] = [
                'id'           => $id,
                'title'        => mb_substr($text, 0, 180),
                'body'         => $text,
                'url'          => 'https://x.com/' . rawurlencode($handle) . '/status/' . $id,
                'published_at' => $published,
            ];
        }
        return $items;
    }
}
