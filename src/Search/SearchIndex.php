<?php

declare(strict_types=1);

namespace Dashboard\Search;

use Dashboard\DB\Database;
use Dashboard\Knowledge\Vectors;
use Dashboard\Ollama\OllamaClient;
use Dashboard\Ollama\OllamaSettings;
use Dashboard\Support\HttpClient;

/**
 * Copies stored text into search_chunks, one row per source.
 * Embeddings are filled a few at a time and are optional.
 */
final class SearchIndex
{
    public function __construct(
        private readonly Database $db,
        private readonly ?HttpClient $http = null,
    ) {}

    public function sync(int $userId): void
    {
        $chunks = $this->collect($userId);
        $kept = [];
        foreach ($this->db->fetchAll(
            'SELECT dataset, source_id, content_hash, embedding FROM search_chunks WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $kept[$row['dataset'] . '|' . $row['source_id']] = $row;
        }

        $this->db->transaction(function (Database $db) use ($userId, $chunks, $kept): void {
            $db->execute('DELETE FROM search_chunks WHERE user_id = ?', [$userId]);
            foreach ($chunks as $chunk) {
                $previous = $kept[$chunk['dataset'] . '|' . $chunk['source_id']] ?? null;
                $json = null;
                if (is_array($previous) && (string) $previous['content_hash'] === $chunk['hash']) {
                    $json = self::asJson($previous['embedding'] ?? null);
                }
                if ($json === null) {
                    $db->execute(
                        'INSERT INTO search_chunks (user_id, dataset, source_id, title, body, url, content_hash)
                         VALUES (?, ?, ?, ?, ?, ?, ?)',
                        [$userId, $chunk['dataset'], $chunk['source_id'], $chunk['title'], $chunk['body'], $chunk['url'], $chunk['hash']]
                    );
                } else {
                    $db->execute(
                        'INSERT INTO search_chunks (user_id, dataset, source_id, title, body, url, content_hash, embedding)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?::jsonb)',
                        [$userId, $chunk['dataset'], $chunk['source_id'], $chunk['title'], $chunk['body'], $chunk['url'], $chunk['hash'], $json]
                    );
                }
            }
        });

        $this->embedMissing($userId);
    }

    /**
     * @param list<string> $datasets
     * @return list<array{dataset: string, source_id: string, title: string, body: string, url: ?string, score: float}>
     */
    public function similar(int $userId, string $query, array $datasets): array
    {
        $vec = $this->embed(mb_substr($query, 0, 2000));
        if ($vec === null) {
            return [];
        }
        $rows = $this->db->fetchAll(
            "SELECT dataset, source_id, title, body, url, embedding
             FROM search_chunks
             WHERE user_id = ? AND dataset = ANY (string_to_array(?, ',')) AND embedding IS NOT NULL
             LIMIT 800",
            [$userId, implode(',', $datasets)]
        );
        $scored = [];
        foreach ($rows as $row) {
            $stored = is_string($row['embedding']) ? json_decode($row['embedding'], true) : $row['embedding'];
            if (!is_array($stored)) {
                continue;
            }
            $score = Vectors::cosine($vec, $stored);
            if ($score < 0.25) {
                continue;
            }
            $scored[] = [
                'dataset'   => (string) $row['dataset'],
                'source_id' => (string) $row['source_id'],
                'title'     => (string) $row['title'],
                'body'      => (string) $row['body'],
                'url'       => $row['url'] !== null ? (string) $row['url'] : null,
                'score'     => $score,
            ];
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, 20);
    }

    /**
     * @return list<array{dataset: string, source_id: string, title: string, body: string, url: ?string, hash: string}>
     */
    private function collect(int $userId): array
    {
        $chunks = [];
        $add = function (string $dataset, string $sourceId, string $title, string $body, ?string $url) use (&$chunks): void {
            $title = $this->plain($title, 300);
            $body = $this->plain($body, 8000);
            if ($title === '' && $body === '') {
                return;
            }
            if ($title === '') {
                $title = mb_substr($body, 0, 300);
            }
            $chunks[$dataset . '|' . $sourceId] = [
                'dataset'   => $dataset,
                'source_id' => $sourceId,
                'title'     => $title,
                'body'      => $body,
                'url'       => $url,
                'hash'      => hash('sha256', $title . "\n" . $body),
            ];
        };

        foreach ($this->db->fetchAll(
            'SELECT id, title, body FROM pages WHERE user_id = ? AND archived_at IS NULL',
            [$userId]
        ) as $row) {
            $add('pages', (string) $row['id'], (string) $row['title'], (string) $row['body'], '/brain?page=' . $row['id']);
        }

        foreach ($this->db->fetchAll(
            'SELECT c.id, c.title, c.url, c.description, s.name AS source_name
             FROM feed_cache c
             JOIN feed_sources s ON s.id = c.source_id
             WHERE s.user_id IS NULL OR s.user_id = ?
                OR EXISTS (
                    SELECT 1 FROM user_feeds uf
                    WHERE uf.source_id = s.id AND uf.user_id = ? AND uf.enabled = 1
                )
             ORDER BY c.id DESC
             LIMIT 400',
            [$userId, $userId]
        ) as $row) {
            $add('feeds', (string) $row['id'], (string) $row['source_name'] . ': ' . $row['title'], (string) ($row['description'] ?? ''), (string) $row['url']);
        }

        foreach ($this->db->fetchAll(
            'SELECT id, name, key, unit FROM metrics WHERE user_id = ? AND archived_at IS NULL',
            [$userId]
        ) as $row) {
            $add('metrics', (string) $row['id'], (string) $row['name'], trim((string) $row['key'] . ' ' . (string) ($row['unit'] ?? '')), '/settings/trackers');
        }
        foreach ($this->db->fetchAll(
            'SELECT d.id, m.name, d.note
             FROM datapoints d
             JOIN metrics m ON m.id = d.metric_id
             WHERE d.user_id = ? AND d.note IS NOT NULL AND btrim(d.note) <> \'\'
             ORDER BY d.id DESC
             LIMIT 500',
            [$userId]
        ) as $row) {
            $add('metrics', 'note-' . $row['id'], (string) $row['name'], (string) $row['note'], '/settings/trackers');
        }

        foreach ($this->db->fetchAll(
            'SELECT i.id, i.title, i.notes, l.name AS list_name
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE l.user_id = ?',
            [$userId]
        ) as $row) {
            $add('todos', (string) $row['id'], (string) $row['list_name'] . ': ' . $row['title'], (string) ($row['notes'] ?? ''), '/todos');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, title, url FROM bookmarks WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $add('bookmarks', (string) $row['id'], (string) $row['title'], (string) $row['url'], (string) $row['url']);
        }

        foreach ($this->db->fetchAll(
            'SELECT id, name, content FROM user_clipboards WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $add('clipboards', (string) $row['id'], (string) $row['name'], (string) $row['content'], '/settings/clipboards');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, name, kind FROM money_accounts WHERE user_id = ? AND archived_at IS NULL',
            [$userId]
        ) as $row) {
            $add('money', 'account-' . $row['id'], (string) $row['name'], (string) $row['kind'], '/settings/money');
        }
        foreach ($this->db->fetchAll(
            'SELECT t.id, t.payee, t.category, t.note, a.name AS account_name
             FROM money_transactions t
             JOIN money_accounts a ON a.id = t.account_id
             WHERE t.user_id = ?',
            [$userId]
        ) as $row) {
            $add('money', 'tx-' . $row['id'], (string) ($row['payee'] ?? $row['category']), trim((string) $row['category'] . ' ' . (string) ($row['note'] ?? '') . ' ' . $row['account_name']), '/settings/money');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, symbol, venue FROM invest_positions WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $add('invest', 'pos-' . $row['id'], (string) $row['symbol'], (string) $row['venue'], '/settings/investing');
        }
        foreach ($this->db->fetchAll(
            'SELECT id, symbol, kind, note FROM invest_activity WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $add('invest', 'act-' . $row['id'], (string) ($row['symbol'] ?? $row['kind']), trim((string) $row['kind'] . ' ' . (string) ($row['note'] ?? '')), '/settings/investing');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, type, title, config FROM dashboard_widgets WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $config = is_string($row['config']) ? json_decode($row['config'], true) : $row['config'];
            $markup = is_array($config) && is_string($config['markup'] ?? null) ? $config['markup'] : '';
            $add('widgets', (string) $row['id'], (string) ($row['title'] ?? $row['type']), trim((string) $row['type'] . ' ' . $markup), '/');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, original_name FROM user_files WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $add('files', (string) $row['id'], (string) $row['original_name'], (string) $row['original_name'], '/settings/clipboards?tab=files');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, symbol FROM user_stock_symbols WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $add('stocks', (string) $row['id'], (string) $row['symbol'], (string) $row['symbol'], '/settings/stocks');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, name FROM user_cloudpad_canvases WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $add('cloudpad', (string) $row['id'], (string) $row['name'], (string) $row['name'], '/settings/clipboards?tab=cloudpad');
        }

        foreach ($this->db->fetchAll(
            'SELECT id, dataset, source_key, title, body, url FROM media_cards WHERE user_id = ? ORDER BY id DESC LIMIT 400',
            [$userId]
        ) as $row) {
            $add((string) $row['dataset'], (string) $row['id'], (string) $row['source_key'] . ': ' . $row['title'], (string) $row['body'], (string) $row['url']);
        }

        foreach ($this->db->fetchAll(
            'SELECT id, subreddit, title, selftext, flair, permalink FROM reddit_posts WHERE user_id = ? ORDER BY created_utc DESC LIMIT 400',
            [$userId]
        ) as $row) {
            $body = trim((string) $row['selftext'] . ' ' . (string) ($row['flair'] ?? ''));
            $add('reddit', 'post-' . $row['id'], 'r/' . $row['subreddit'] . ': ' . $row['title'], $body, (string) $row['permalink']);
        }

        return array_values($chunks);
    }

    private function embedMissing(int $userId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id, title, body FROM search_chunks WHERE user_id = ? AND embedding IS NULL ORDER BY id DESC LIMIT 8',
            [$userId]
        );
        foreach ($rows as $row) {
            $vec = $this->embed(mb_substr((string) $row['title'] . ' ' . (string) $row['body'], 0, 2000));
            if ($vec === null) {
                return;
            }
            $this->db->execute(
                'UPDATE search_chunks SET embedding = ?::jsonb WHERE id = ? AND user_id = ?',
                [json_encode($vec, JSON_THROW_ON_ERROR), $row['id'], $userId]
            );
        }
    }

    /**
     * @return list<float>|null
     */
    private function embed(string $text): ?array
    {
        if (trim($text) === '') {
            return null;
        }
        $target = OllamaSettings::effective((new OllamaSettings($this->db))->get(), null);

        return (new OllamaClient())->embeddings($target['base_url'], $target['embed_model'], $text, 8);
    }

    private function plain(string $text, int $max): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim(mb_substr(trim($text), 0, $max));
    }

    private static function asJson(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }
        return null;
    }
}
