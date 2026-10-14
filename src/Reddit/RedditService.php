<?php

declare(strict_types=1);

namespace Dashboard\Reddit;

use Dashboard\DB\Database;
use Dashboard\Media\MediaFilter;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * The list of subreddits a person tracks, and the posts saved from them.
 */
final class RedditService
{
    public const MAX_SUBS = 20;

    public const KEEP = 80;

    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId): array
    {
        return array_map(
            [$this, 'formatSub'],
            $this->db->fetchAll(
                'SELECT s.*,
                        (SELECT COUNT(*) FROM reddit_posts p WHERE p.user_id = s.user_id AND p.subreddit = s.name) AS post_count
                 FROM reddit_subs s
                 WHERE s.user_id = ?
                 ORDER BY s.name ASC',
                [$userId]
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function add(int $userId, string $name, string $sort = 'hot', string $include = '', string $exclude = ''): array
    {
        $name = self::normalizeName($name);
        $sort = self::normalizeSort($sort);
        $include = self::words($include);
        $exclude = self::words($exclude);

        $existing = $this->db->fetchOne(
            'SELECT id FROM reddit_subs WHERE user_id = ? AND name = ?',
            [$userId, $name]
        );
        if ($existing === null) {
            $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM reddit_subs WHERE user_id = ?', [$userId]);
            if ($count >= self::MAX_SUBS) {
                throw new ValidationException('You can track up to ' . self::MAX_SUBS . ' subreddits.');
            }
            $this->db->execute(
                'INSERT INTO reddit_subs (user_id, name, sort, include_words, exclude_words) VALUES (?, ?, ?, ?, ?)',
                [$userId, $name, $sort, $include, $exclude]
            );
        } else {
            $this->db->execute(
                'UPDATE reddit_subs SET sort = ?, include_words = ?, exclude_words = ? WHERE id = ? AND user_id = ?',
                [$sort, $include, $exclude, (int) $existing['id'], $userId]
            );
        }
        return $this->sub($userId, $name);
    }

    public function remove(int $userId, string $name): void
    {
        $name = self::normalizeName($name);
        $gone = $this->db->execute(
            'DELETE FROM reddit_subs WHERE user_id = ? AND name = ?',
            [$userId, $name]
        );
        if ($gone < 1) {
            throw new NotFoundException('That subreddit is not tracked.');
        }
        $this->db->execute(
            'DELETE FROM reddit_posts WHERE user_id = ? AND subreddit = ?',
            [$userId, $name]
        );
    }

    /**
     * Fetch every tracked subreddit. Stops early when Reddit says to slow down.
     *
     * @return list<string> errors, one per subreddit that failed
     */
    public function refresh(int $userId, ?RedditClient $client = null): array
    {
        $client ??= RedditClient::fromConfig();
        $errors = [];
        $first = true;
        foreach ($this->list($userId) as $sub) {
            if (!$first && $client->rateLimited()) {
                $errors[] = $sub['name'] . ': skipped, Reddit rate limit';
                $this->mark($userId, (string) $sub['name'], 'Skipped, Reddit rate limit.');
                continue;
            }
            $first = false;
            try {
                $posts = $client->listing((string) $sub['name'], (string) $sub['sort']);
                $this->savePosts($userId, (string) $sub['name'], $posts);
                $this->mark($userId, (string) $sub['name'], null);
            } catch (\Throwable $e) {
                $message = $this->safeError($e);
                $errors[] = $sub['name'] . ': ' . $message;
                $this->mark($userId, (string) $sub['name'], $message);
            }
        }
        return $errors;
    }

    /**
     * @param list<array{id: string, title: string, author: string, score: int, num_comments: int, url: string, permalink: string, created_utc: string, flair: ?string, selftext: string}> $posts
     */
    public function savePosts(int $userId, string $subreddit, array $posts): int
    {
        $subreddit = self::normalizeName($subreddit);
        $saved = 0;
        foreach ($posts as $post) {
            if (($post['id'] ?? '') === '' || !str_starts_with((string) $post['permalink'], 'https://')) {
                continue;
            }
            $this->db->execute(
                'INSERT INTO reddit_posts
                    (user_id, subreddit, external_id, title, author, score, num_comments, url, permalink, created_utc, flair, selftext)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, external_id) DO UPDATE SET
                    subreddit = EXCLUDED.subreddit,
                    title = EXCLUDED.title,
                    author = EXCLUDED.author,
                    score = EXCLUDED.score,
                    num_comments = EXCLUDED.num_comments,
                    url = EXCLUDED.url,
                    permalink = EXCLUDED.permalink,
                    flair = EXCLUDED.flair,
                    selftext = EXCLUDED.selftext,
                    fetched_at = (now() AT TIME ZONE \'UTC\')',
                [
                    $userId,
                    $subreddit,
                    $post['id'],
                    $post['title'],
                    $post['author'],
                    $post['score'],
                    $post['num_comments'],
                    $post['url'],
                    $post['permalink'],
                    $post['created_utc'],
                    $post['flair'],
                    $post['selftext'],
                ]
            );
            $saved++;
        }
        $this->db->execute(
            'DELETE FROM reddit_posts WHERE id IN (
                SELECT id FROM reddit_posts
                WHERE user_id = ? AND subreddit = ?
                ORDER BY created_utc DESC, id DESC
                OFFSET 80
            )',
            [$userId, $subreddit]
        );
        return $saved;
    }

    /**
     * @param array{subreddit?: string, since?: string, min_score?: int, keyword?: string, limit?: int} $filter
     * @return list<array<string, mixed>>
     */
    public function posts(int $userId, array $filter = []): array
    {
        $subreddit = isset($filter['subreddit']) && $filter['subreddit'] !== ''
            ? self::normalizeName((string) $filter['subreddit'])
            : '';
        $since = self::since($filter['since'] ?? null);
        $min = max(0, (int) ($filter['min_score'] ?? 0));
        $keyword = self::words((string) ($filter['keyword'] ?? ''));
        $limit = (int) ($filter['limit'] ?? 25);
        if ($limit < 1 || $limit > 100) {
            throw ValidationException::withFields(['limit' => 'Limit must be 1–100.']);
        }

        $rows = $this->db->fetchAll(
            'SELECT p.*, s.include_words, s.exclude_words
             FROM reddit_posts p
             LEFT JOIN reddit_subs s ON s.user_id = p.user_id AND s.name = p.subreddit
             WHERE p.user_id = ?
               AND (p.subreddit = ? OR ? = \'\')
               AND p.created_utc >= ?
               AND p.score >= ?
             ORDER BY p.created_utc DESC, p.id DESC
             LIMIT 200',
            [$userId, $subreddit, $subreddit, $since, $min]
        );

        $out = [];
        foreach ($rows as $row) {
            $title = (string) $row['title'];
            $body = trim((string) $row['selftext'] . ' ' . (string) ($row['flair'] ?? ''));
            $url = (string) $row['permalink'];
            if (!MediaFilter::allows($title, $body, $url, (string) ($row['include_words'] ?? ''), (string) ($row['exclude_words'] ?? ''), false)) {
                continue;
            }
            if ($keyword !== '' && !MediaFilter::allows($title, $body, $url, $keyword, '', false)) {
                continue;
            }
            $out[] = $this->formatPost($row);
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return array{post_id: string, comments: list<array{id: string, author: string, score: int, body: string, created_utc: string}>}
     */
    public function comments(int $userId, string $postId, ?RedditClient $client = null, int $limit = 20): array
    {
        $postId = strtolower(trim($postId));
        if (preg_match('/^[a-z0-9]{3,12}$/', $postId) !== 1) {
            throw new NotFoundException('Post not found.');
        }
        $row = $this->db->fetchOne(
            'SELECT external_id FROM reddit_posts WHERE user_id = ? AND external_id = ?',
            [$userId, $postId]
        );
        if ($row === null) {
            throw new NotFoundException('Post not found.');
        }
        $client ??= RedditClient::fromConfig();
        return [
            'post_id'  => $postId,
            'comments' => $client->comments($postId, $limit),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sub(int $userId, string $name): array
    {
        $row = $this->db->fetchOne(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM reddit_posts p WHERE p.user_id = s.user_id AND p.subreddit = s.name) AS post_count
             FROM reddit_subs s WHERE s.user_id = ? AND s.name = ?',
            [$userId, $name]
        );
        if ($row === null) {
            throw new NotFoundException('That subreddit is not tracked.');
        }
        return $this->formatSub($row);
    }

    private function mark(int $userId, string $name, ?string $error): void
    {
        $this->db->execute(
            'UPDATE reddit_subs SET last_error = ?, fetched_at = (now() AT TIME ZONE \'UTC\') WHERE user_id = ? AND name = ?',
            [$error, $userId, $name]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatSub(array $row): array
    {
        return [
            'name'       => (string) $row['name'],
            'sort'       => (string) $row['sort'],
            'include'    => (string) $row['include_words'],
            'exclude'    => (string) $row['exclude_words'],
            'posts'      => (int) $row['post_count'],
            'last_error' => $row['last_error'] !== null && $row['last_error'] !== '' ? (string) $row['last_error'] : null,
            'fetched_at' => $row['fetched_at'] !== null ? (string) $row['fetched_at'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPost(array $row): array
    {
        return [
            'id'           => (string) $row['external_id'],
            'subreddit'    => (string) $row['subreddit'],
            'title'        => (string) $row['title'],
            'author'       => (string) $row['author'],
            'score'        => (int) $row['score'],
            'num_comments' => (int) $row['num_comments'],
            'url'          => (string) $row['url'],
            'permalink'    => (string) $row['permalink'],
            'created_utc'  => (string) $row['created_utc'],
            'flair'        => $row['flair'] !== null ? (string) $row['flair'] : null,
            'selftext'     => (string) $row['selftext'],
        ];
    }

    public static function normalizeName(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('~reddit\.com/r/([A-Za-z0-9_]{2,21})~i', $raw, $m) === 1) {
            $raw = $m[1];
        }
        $raw = preg_replace('~^r/~i', '', $raw) ?? $raw;
        $name = strtolower($raw);
        if (preg_match('/^[a-z0-9_]{2,21}$/', $name) !== 1) {
            throw ValidationException::withFields(['name' => 'Use a subreddit name like news or r/programming.']);
        }
        return $name;
    }

    public static function normalizeSort(string $sort): string
    {
        $sort = strtolower(trim($sort));
        if ($sort === '') {
            return 'hot';
        }
        if (!in_array($sort, ['hot', 'new', 'top'], true)) {
            throw ValidationException::withFields(['sort' => 'Sort must be hot, new, or top.']);
        }
        return $sort;
    }

    private static function words(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) > 200) {
            throw ValidationException::withFields(['keyword' => 'Filters must be 200 characters or fewer.']);
        }
        return $value;
    }

    private static function since(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '1970-01-01 00:00:00';
        }
        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            $text .= ' 00:00:00';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text) !== 1) {
            throw ValidationException::withFields(['since' => 'Use a date like 2026-10-01.']);
        }
        return $text;
    }

    private function safeError(\Throwable $e): string
    {
        $message = trim($e->getMessage());
        if ($message === '' || preg_match('/Bearer\s+\S{8,}|Basic\s+\S{8,}/', $message) === 1) {
            $message = 'Could not refresh this subreddit.';
        }
        return mb_substr($message, 0, 300);
    }
}
