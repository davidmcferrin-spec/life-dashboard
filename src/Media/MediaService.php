<?php

declare(strict_types=1);

namespace Dashboard\Media;

use Dashboard\DB\Database;
use Dashboard\Support\ValidationException;

/**
 * Fetches and stores cards, then filters them for one widget.
 * A dashboard read refreshes at most a couple of stale sources. The timer does the rest.
 */
final class MediaService
{
    private const STALE_SECONDS = 1200;

    public function __construct(
        private readonly Database $db,
        private readonly ?MediaHttp $http = null,
    ) {}

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function payload(int $userId, string $dataset, array $config): array
    {
        $this->sync($userId, false, 2, $dataset);
        $sources = $this->safeParse($dataset, (string) ($config['sources'] ?? ''));
        $keys = array_column($sources, 'key');
        $labels = [];
        $errors = [];
        if ($keys !== []) {
            $rows = $this->db->fetchAll(
                "SELECT source_key, label, last_error FROM media_sources
                 WHERE user_id = ? AND dataset = ? AND source_key = ANY (string_to_array(?, ','))",
                [$userId, $dataset, implode(',', $keys)]
            );
            foreach ($rows as $row) {
                $labels[(string) $row['source_key']] = (string) $row['label'];
                if ($row['last_error'] !== null && $row['last_error'] !== '') {
                    $errors[] = (string) $row['label'] . ': ' . $row['last_error'];
                }
            }
        }

        $cards = [];
        if ($keys !== []) {
            $stored = $this->db->fetchAll(
                "SELECT source_key, external_id, title, body, url, published_at
                 FROM media_cards
                 WHERE user_id = ? AND dataset = ? AND source_key = ANY (string_to_array(?, ','))
                 ORDER BY published_at DESC NULLS LAST, id DESC
                 LIMIT 80",
                [$userId, $dataset, implode(',', $keys)]
            );
            $include = (string) ($config['include'] ?? '');
            $exclude = (string) ($config['exclude'] ?? '');
            $hideShorts = $dataset === 'youtube' && !empty($config['hide_shorts']);
            $limit = (int) ($config['limit'] ?? 8);
            foreach ($stored as $row) {
                if (!MediaFilter::allows((string) $row['title'], (string) $row['body'], (string) $row['url'], $include, $exclude, $hideShorts)) {
                    continue;
                }
                $thumb = null;
                if ($dataset === 'youtube' && preg_match('/^[\w-]{11}$/', (string) $row['external_id']) === 1) {
                    $thumb = YouTubeFeed::thumb((string) $row['external_id']);
                }
                $cards[] = [
                    'title'        => (string) $row['title'],
                    'body'         => mb_substr(trim((string) $row['body']), 0, 220),
                    'url'          => (string) $row['url'],
                    'source'       => $labels[(string) $row['source_key']] ?? (string) $row['source_key'],
                    'published_at' => $row['published_at'] !== null ? (string) $row['published_at'] : null,
                    'thumb'        => $thumb,
                ];
                if (count($cards) >= $limit) {
                    break;
                }
            }
        }

        $empty = null;
        if ($cards === [] && $sources === []) {
            $empty = match ($dataset) {
                'youtube' => 'Add channels in the widget settings. One @handle, channel link, or channel id per line.',
                'reddit'  => 'Add subreddits in the widget settings. One name per line, such as news or r/news.',
                default   => 'Add accounts in the widget settings. One handle per line. X needs X_BEARER_TOKEN on the server.',
            };
        } elseif ($cards === [] && $errors === []) {
            $empty = 'No matching items yet. New items are checked about every half hour.';
        }

        $interval = (int) ($config['interval'] ?? 12);
        if (!in_array($interval, [8, 12, 20], true)) {
            $interval = 12;
        }
        $motion = (string) ($config['motion'] ?? 'wipe');
        if (!in_array($motion, ['wipe', 'push'], true)) {
            $motion = 'wipe';
        }

        return [
            'metric_ids' => [],
            'dataset'    => $dataset,
            'motion'     => $motion,
            'interval'   => $interval,
            'cards'      => $cards,
            'errors'     => $errors,
            'empty'      => $empty,
        ];
    }

    public function sync(int $userId, bool $force, int $maxSources, ?string $prefer = null): void
    {
        $wanted = ['youtube' => [], 'reddit' => [], 'x' => []];
        $widgets = $this->db->fetchAll(
            "SELECT type, config FROM dashboard_widgets WHERE user_id = ? AND type IN ('youtube', 'reddit', 'x')",
            [$userId]
        );
        foreach ($widgets as $widget) {
            $type = (string) $widget['type'];
            $config = $widget['config'];
            if (is_string($config)) {
                $decoded = json_decode($config, true);
                $config = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($config)) {
                continue;
            }
            foreach ($this->safeParse($type, (string) ($config['sources'] ?? '')) as $source) {
                $wanted[$type][$source['key']] = $source;
            }
        }

        foreach ($wanted as $dataset => $sources) {
            $this->retain($userId, $dataset, array_keys($sources));
        }

        $known = [];
        foreach ($this->db->fetchAll(
            'SELECT dataset, source_key, remote_id, fetched_at FROM media_sources WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $known[$row['dataset'] . '|' . $row['source_key']] = $row;
        }

        $queue = [];
        foreach ($wanted as $dataset => $sources) {
            foreach ($sources as $source) {
                $row = $known[$dataset . '|' . $source['key']] ?? null;
                $fetched = is_array($row) ? (string) ($row['fetched_at'] ?? '') : '';
                if (!$this->stale($fetched, $force)) {
                    continue;
                }
                if (is_array($row) && is_string($row['remote_id'] ?? null)) {
                    $source['remote_id'] = $row['remote_id'];
                }
                $source['dataset'] = $dataset;
                $queue[] = $source;
            }
        }
        usort($queue, static function (array $a, array $b) use ($prefer): int {
            $ap = $a['dataset'] === $prefer ? 0 : 1;
            $bp = $b['dataset'] === $prefer ? 0 : 1;
            return $ap <=> $bp;
        });

        $http = $this->http ?? new MediaHttp($force ? 10 : 6);
        $youtube = new YouTubeFeed($http);
        $reddit = new RedditFeed($http);
        $x = new XFeed($http);
        $done = 0;
        foreach ($queue as $source) {
            if ($done >= $maxSources) {
                break;
            }
            $done++;
            $dataset = (string) $source['dataset'];
            try {
                $fetched = match ($dataset) {
                    'youtube' => $youtube->fetch($source),
                    'reddit'  => $reddit->fetch($source),
                    default   => $x->fetch($source),
                };
                $this->saveSource($userId, $dataset, $source, $fetched['remote_id'], null);
                $this->saveItems($userId, $dataset, (string) $source['key'], $fetched['items']);
            } catch (\Throwable $e) {
                $this->saveSource($userId, $dataset, $source, $source['remote_id'] ?? null, $this->safeError($e));
            }
        }
    }

    /**
     * @return list<array{key: string, label: string, channel_id: ?string, handle: ?string, page: ?string}>
     */
    private function safeParse(string $dataset, string $text): array
    {
        try {
            return MediaSources::parse($dataset, $text);
        } catch (ValidationException) {
            return [];
        }
    }

    /**
     * @param list<string> $keys
     */
    private function retain(int $userId, string $dataset, array $keys): void
    {
        if ($keys === []) {
            $this->db->execute('DELETE FROM media_cards WHERE user_id = ? AND dataset = ?', [$userId, $dataset]);
            $this->db->execute('DELETE FROM media_sources WHERE user_id = ? AND dataset = ?', [$userId, $dataset]);
            return;
        }
        $csv = implode(',', $keys);
        $this->db->execute(
            "DELETE FROM media_cards WHERE user_id = ? AND dataset = ? AND source_key <> ALL (string_to_array(?, ','))",
            [$userId, $dataset, $csv]
        );
        $this->db->execute(
            "DELETE FROM media_sources WHERE user_id = ? AND dataset = ? AND source_key <> ALL (string_to_array(?, ','))",
            [$userId, $dataset, $csv]
        );
    }

    /**
     * @param array{key: string, label: string} $source
     */
    private function saveSource(int $userId, string $dataset, array $source, ?string $remoteId, ?string $error): void
    {
        $this->db->execute(
            'INSERT INTO media_sources (user_id, dataset, source_key, label, remote_id, last_error, fetched_at)
             VALUES (?, ?, ?, ?, ?, ?, (now() AT TIME ZONE \'UTC\'))
             ON CONFLICT (user_id, dataset, source_key) DO UPDATE SET
                label = EXCLUDED.label,
                remote_id = COALESCE(EXCLUDED.remote_id, media_sources.remote_id),
                last_error = EXCLUDED.last_error,
                fetched_at = EXCLUDED.fetched_at',
            [$userId, $dataset, $source['key'], mb_substr($source['label'], 0, 200), $remoteId, $error]
        );
    }

    /**
     * @param list<array{id: string, title: string, body: string, url: string, published_at: ?string}> $items
     */
    private function saveItems(int $userId, string $dataset, string $sourceKey, array $items): void
    {
        foreach ($items as $item) {
            $title = trim(html_entity_decode(strip_tags($item['title']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $body = trim(html_entity_decode(strip_tags($item['body']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $title = mb_substr(preg_replace('/\s+/u', ' ', $title) ?? $title, 0, 300);
            $body = mb_substr(preg_replace('/\s+/u', ' ', $body) ?? $body, 0, 4000);
            if ($title === '' || !str_starts_with($item['url'], 'https://')) {
                continue;
            }
            $published = is_string($item['published_at']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $item['published_at']) === 1
                ? $item['published_at']
                : null;
            $this->db->execute(
                'INSERT INTO media_cards (user_id, dataset, source_key, external_id, title, body, url, published_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, dataset, external_id) DO UPDATE SET
                    source_key = EXCLUDED.source_key,
                    title = EXCLUDED.title,
                    body = EXCLUDED.body,
                    url = EXCLUDED.url,
                    published_at = EXCLUDED.published_at,
                    fetched_at = (now() AT TIME ZONE \'UTC\')',
                [$userId, $dataset, $sourceKey, mb_substr($item['id'], 0, 200), $title, $body, $item['url'], $published]
            );
        }
        $this->db->execute(
            'DELETE FROM media_cards WHERE id IN (
                SELECT id FROM media_cards
                WHERE user_id = ? AND dataset = ? AND source_key = ?
                ORDER BY published_at DESC NULLS LAST, id DESC
                OFFSET 40
            )',
            [$userId, $dataset, $sourceKey]
        );
    }

    private function stale(string $fetchedAt, bool $force): bool
    {
        if ($force || $fetchedAt === '') {
            return true;
        }
        $ts = strtotime($fetchedAt . ' UTC');
        return $ts === false || (time() - $ts) > self::STALE_SECONDS;
    }

    private function safeError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '' || preg_match('/Bearer\s+\S{8,}/', $message) === 1) {
            $message = 'Could not refresh this source.';
        }
        return mb_substr($message, 0, 300);
    }
}
