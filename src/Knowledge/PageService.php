<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

use Dashboard\Audit\AuditLog;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Support\HttpClient;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Markdown vault: revisions, wikilinks, full-text search, optional embeddings.
 */
final class PageService
{
    public const KINDS = ['note', 'daily', 'project', 'reference', 'review'];
    public const MAX_BODY = 200000;

    public function __construct(
        private readonly Database $db,
        private readonly AuditLog $audit,
        private readonly ?HttpClient $http = null,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(int $userId, ?string $query = null, int $limit = 100): array
    {
        $limit = max(1, min(200, $limit));
        $query = $query !== null ? trim($query) : '';
        if ($query === '') {
            return array_map(
                fn (array $row): array => $this->brief($row),
                $this->db->fetchAll(
                    "SELECT id, title, slug, kind, updated_at FROM pages
                     WHERE user_id = ? AND archived_at IS NULL
                     ORDER BY updated_at DESC, id DESC LIMIT {$limit}",
                    [$userId]
                )
            );
        }

        $fts = array_map(
            fn (array $row): array => $this->brief($row),
            $this->db->fetchAll(
                "SELECT id, title, slug, kind, updated_at,
                        ts_rank(to_tsvector('english', title || ' ' || body), plainto_tsquery('english', ?)) AS rank
                 FROM pages
                 WHERE user_id = ? AND archived_at IS NULL
                   AND to_tsvector('english', title || ' ' || body) @@ plainto_tsquery('english', ?)
                 ORDER BY rank DESC, updated_at DESC
                 LIMIT {$limit}",
                [$query, $userId, $query]
            )
        );
        $semantic = $this->semantic($userId, $query, $limit);
        return $this->mergeHits($fts, $semantic, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $userId, int $limit = 8): array
    {
        return $this->list($userId, null, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM pages WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Page not found.');
        }
        $page = $this->full($row);
        $page['backlinks'] = $this->backlinks($userId, $id);
        $page['links'] = $this->outgoing($userId, $id);
        $page['revisions'] = $this->revisions($userId, $id);
        return $page;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input, string $actor = 'user', ?string $tool = null): array
    {
        $title = $this->cleanTitle($input['title'] ?? null);
        $this->assertUniqueTitle($userId, $title, null);
        $kind = $this->cleanKind($input['kind'] ?? 'note');
        $body = $this->cleanBody($input['body'] ?? '');
        $slug = $this->uniqueSlug($userId, $title);

        $id = (int) $this->db->fetchScalar(
            'INSERT INTO pages (user_id, title, slug, kind, body) VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$userId, $title, $slug, $kind, $body]
        );
        $this->addRevision($userId, $id, 1, $title, $body);
        $this->syncLinks($userId, $id, $body);
        $this->relink($userId);
        $this->maybeEmbed($userId, $id, $title, $body);
        $this->audit->record($userId, $actor, $tool, 'page.create', 'page:' . $id, ['title' => $title]);
        return $this->get($userId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $userId, int $id, array $input, string $actor = 'user', ?string $tool = null): array
    {
        $page = $this->get($userId, $id);
        $title = array_key_exists('title', $input) ? $this->cleanTitle($input['title']) : (string) $page['title'];
        $kind  = array_key_exists('kind', $input) ? $this->cleanKind($input['kind']) : (string) $page['kind'];
        $body  = array_key_exists('body', $input) ? $this->cleanBody($input['body']) : (string) $page['body'];
        if ($title !== $page['title']) {
            $this->assertUniqueTitle($userId, $title, $id);
        }
        if ($title === $page['title'] && $kind === $page['kind'] && $body === $page['body']) {
            return $page;
        }
        $version = (int) $this->db->fetchScalar('SELECT COALESCE(MAX(version), 0) + 1 FROM page_revisions WHERE page_id = ?', [$id]);
        $this->db->execute(
            "UPDATE pages SET title = ?, kind = ?, body = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$title, $kind, $body, $id, $userId]
        );
        $this->addRevision($userId, $id, $version, $title, $body);
        $this->syncLinks($userId, $id, $body);
        $this->relink($userId);
        $this->maybeEmbed($userId, $id, $title, $body);
        $this->audit->record($userId, $actor, $tool, 'page.update', 'page:' . $id, ['title' => $title]);
        return $this->get($userId, $id);
    }

    /**
     * Create or replace a page with this slug. Used by the weekly money review.
     *
     * @return array<string, mixed>
     */
    public function upsertSlug(int $userId, string $slug, string $title, string $body, string $kind, string $actor = 'user'): array
    {
        $row = $this->db->fetchOne('SELECT id FROM pages WHERE user_id = ? AND slug = ?', [$userId, $slug]);
        if ($row === null) {
            $title = $this->cleanTitle($title);
            $body  = $this->cleanBody($body);
            $kind  = $this->cleanKind($kind);
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO pages (user_id, title, slug, kind, body) VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$userId, $title, $slug, $kind, $body]
            );
            $this->addRevision($userId, $id, 1, $title, $body);
            $this->syncLinks($userId, $id, $body);
            $this->relink($userId);
            $this->maybeEmbed($userId, $id, $title, $body);
            $this->audit->record($userId, $actor, null, 'page.create', 'page:' . $id, ['title' => $title]);
            return $this->get($userId, $id);
        }
        return $this->update($userId, (int) $row['id'], ['title' => $title, 'body' => $body, 'kind' => $kind], $actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(int $userId, int $id, int $version, string $actor = 'user', ?string $tool = null): array
    {
        $this->get($userId, $id);
        $rev = $this->db->fetchOne(
            'SELECT title, body FROM page_revisions WHERE page_id = ? AND user_id = ? AND version = ?',
            [$id, $userId, $version]
        );
        if ($rev === null) {
            throw new NotFoundException('Revision not found.');
        }
        return $this->update($userId, $id, ['title' => $rev['title'], 'body' => $rev['body']], $actor, $tool);
    }

    public function archive(int $userId, int $id, string $actor = 'user'): void
    {
        $this->get($userId, $id);
        $this->db->execute(
            "UPDATE pages SET archived_at = (now() AT TIME ZONE 'UTC'), updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        $this->audit->record($userId, $actor, null, 'page.archive', 'page:' . $id);
    }

    /**
     * @param list<float|int> $vector
     */
    public function setEmbedding(int $userId, int $id, array $vector, ?string $model = null): void
    {
        $this->get($userId, $id);
        if ($vector === [] || count($vector) > 4096) {
            throw ValidationException::withFields(['embedding' => 'Embedding must be 1–4096 numbers.']);
        }
        $nums = [];
        foreach ($vector as $n) {
            if (!is_numeric($n)) {
                throw ValidationException::withFields(['embedding' => 'Embedding values must be numbers.']);
            }
            $nums[] = (float) $n;
        }
        $model = $model !== null && $model !== '' ? mb_substr($model, 0, 64) : 'agent';
        $json = json_encode($nums, JSON_THROW_ON_ERROR);
        $this->db->execute(
            "INSERT INTO page_embeddings (page_id, user_id, model, embedding)
             VALUES (?, ?, ?, ?::jsonb)
             ON CONFLICT (page_id) DO UPDATE
                SET model = EXCLUDED.model, embedding = EXCLUDED.embedding,
                    updated_at = (now() AT TIME ZONE 'UTC')",
            [$id, $userId, $model, $json]
        );
    }

    /**
     * @return array{
     *   nodes: list<array<string, mixed>>,
     *   edges: list<array{from: int, to: int}>,
     *   clusters: list<array<string, mixed>>,
     *   tokens: list<array{id: int, name: string}>,
     *   activity: list<array<string, mixed>>
     * }
     */
    public function graph(int $userId): array
    {
        $pages = [];
        foreach ($this->db->fetchAll(
            'SELECT id, title, kind FROM pages WHERE user_id = ? AND archived_at IS NULL ORDER BY id ASC',
            [$userId]
        ) as $row) {
            $pages[] = [
                'id'    => (int) $row['id'],
                'title' => (string) $row['title'],
                'kind'  => (string) $row['kind'],
            ];
        }
        $edges = [];
        foreach ($this->db->fetchAll(
            'SELECT from_page_id, to_page_id FROM page_links WHERE user_id = ? AND to_page_id IS NOT NULL',
            [$userId]
        ) as $row) {
            $edges[] = ['from' => (int) $row['from_page_id'], 'to' => (int) $row['to_page_id']];
        }

        $placed = BrainLayout::place($pages, $edges, $this->savedLayout($userId), $this->embeddings($userId));
        $this->saveLayout($userId, $placed['dirty']);
        $this->pruneLayout($userId);

        $hot = array_flip($this->audit->hotPageIds($userId));
        $known = [];
        $nodes = [];
        foreach ($placed['nodes'] as $node) {
            $known[$node['id']] = true;
            $node['hot'] = isset($hot[$node['id']]);
            $nodes[] = $node;
        }
        $presented = BrainActivity::present($this->audit->recent($userId, 60));
        $activity = [];
        foreach ($presented['activity'] as $item) {
            $pageId = $item['page_id'];
            if ($pageId !== null && !isset($known[(int) $pageId])) {
                $item['page_id'] = null;
            }
            $activity[] = $item;
        }

        return [
            'nodes'    => $nodes,
            'edges'    => $edges,
            'clusters' => $placed['clusters'],
            'tokens'   => $presented['tokens'],
            'activity' => $activity,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revisions(int $userId, int $id): array
    {
        return array_map(static fn (array $row): array => [
            'version'    => (int) $row['version'],
            'title'      => (string) $row['title'],
            'created_at' => (string) $row['created_at'],
        ], $this->db->fetchAll(
            'SELECT version, title, created_at FROM page_revisions WHERE page_id = ? AND user_id = ? ORDER BY version DESC LIMIT 30',
            [$id, $userId]
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function backlinks(int $userId, int $id): array
    {
        return array_map(static fn (array $row): array => [
            'id'    => (int) $row['id'],
            'title' => (string) $row['title'],
            'kind'  => (string) $row['kind'],
        ], $this->db->fetchAll(
            'SELECT p.id, p.title, p.kind FROM page_links l
             JOIN pages p ON p.id = l.from_page_id
             WHERE l.user_id = ? AND l.to_page_id = ? AND p.archived_at IS NULL
             ORDER BY p.title ASC',
            [$userId, $id]
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function outgoing(int $userId, int $id): array
    {
        return array_map(static fn (array $row): array => [
            'title' => (string) $row['to_title'],
            'id'    => $row['to_page_id'] !== null ? (int) $row['to_page_id'] : null,
        ], $this->db->fetchAll(
            'SELECT to_title, to_page_id FROM page_links WHERE user_id = ? AND from_page_id = ? ORDER BY to_title ASC',
            [$userId, $id]
        ));
    }

    private function syncLinks(int $userId, int $pageId, string $body): void
    {
        $this->db->execute('DELETE FROM page_links WHERE from_page_id = ? AND user_id = ?', [$pageId, $userId]);
        foreach (Wikilinks::titles($body) as $title) {
            $this->db->execute(
                'INSERT INTO page_links (user_id, from_page_id, to_title) VALUES (?, ?, ?)',
                [$userId, $pageId, $title]
            );
        }
    }

    private function relink(int $userId): void
    {
        $this->db->execute(
            'UPDATE page_links AS l SET to_page_id = p.id
             FROM pages AS p
             WHERE l.user_id = ? AND p.user_id = l.user_id AND p.archived_at IS NULL
               AND lower(p.title) = lower(l.to_title)',
            [$userId]
        );
        $this->db->execute(
            'UPDATE page_links AS l SET to_page_id = NULL
             WHERE l.user_id = ? AND NOT EXISTS (
                 SELECT 1 FROM pages AS p
                 WHERE p.user_id = l.user_id AND p.archived_at IS NULL AND lower(p.title) = lower(l.to_title)
             )',
            [$userId]
        );
    }

    private function addRevision(int $userId, int $pageId, int $version, string $title, string $body): void
    {
        $this->db->execute(
            'INSERT INTO page_revisions (page_id, user_id, version, title, body) VALUES (?, ?, ?, ?, ?)',
            [$pageId, $userId, $version, $title, $body]
        );
    }

    private function maybeEmbed(int $userId, int $pageId, string $title, string $body): void
    {
        $base = Config::maybe('OLLAMA_URL');
        if ($base === null) {
            return;
        }
        $model = Config::get('OLLAMA_EMBED_MODEL', 'nomic-embed-text');
        $prompt = mb_substr($title . "\n" . $body, 0, 8000);
        try {
            $http = $this->http ?? new HttpClient();
            $res = $http->requestJson(
                'POST',
                rtrim($base, '/') . '/api/embeddings',
                json_encode(['model' => $model, 'prompt' => $prompt], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                20
            );
            $vector = is_array($res) ? ($res['embedding'] ?? ($res['embeddings'][0] ?? null)) : null;
            if (is_array($vector) && $vector !== []) {
                $this->setEmbedding($userId, $pageId, $vector, $model);
            }
        } catch (\Throwable $e) {
            error_log('[Dashboard][brain] embedding skipped: ' . $e->getMessage());
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function semantic(int $userId, string $query, int $limit): array
    {
        $base = Config::maybe('OLLAMA_URL');
        if ($base === null) {
            return [];
        }
        $model = Config::get('OLLAMA_EMBED_MODEL', 'nomic-embed-text');
        try {
            $http = $this->http ?? new HttpClient();
            $res = $http->requestJson(
                'POST',
                rtrim($base, '/') . '/api/embeddings',
                json_encode(['model' => $model, 'prompt' => mb_substr($query, 0, 2000)], JSON_THROW_ON_ERROR),
                ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                20
            );
        } catch (\Throwable) {
            return [];
        }
        $queryVec = is_array($res) ? ($res['embedding'] ?? ($res['embeddings'][0] ?? null)) : null;
        if (!is_array($queryVec) || $queryVec === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.title, p.slug, p.kind, p.updated_at, e.embedding
             FROM page_embeddings e JOIN pages p ON p.id = e.page_id
             WHERE e.user_id = ? AND p.archived_at IS NULL',
            [$userId]
        );
        $scored = [];
        foreach ($rows as $row) {
            $vec = is_string($row['embedding']) ? json_decode($row['embedding'], true) : $row['embedding'];
            if (!is_array($vec)) {
                continue;
            }
            $score = Vectors::cosine($queryVec, $vec);
            if ($score < 0.25) {
                continue;
            }
            $brief = $this->brief($row);
            $brief['score'] = $score;
            $scored[] = $brief;
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /**
     * @param list<array<string, mixed>> $fts
     * @param list<array<string, mixed>> $semantic
     * @return list<array<string, mixed>>
     */
    private function mergeHits(array $fts, array $semantic, int $limit): array
    {
        $byId = [];
        foreach ($fts as $row) {
            $byId[$row['id']] = $row;
        }
        foreach ($semantic as $row) {
            if (!isset($byId[$row['id']])) {
                $byId[$row['id']] = $row;
            }
        }
        return array_slice(array_values($byId), 0, $limit);
    }

    private function assertUniqueTitle(int $userId, string $title, ?int $exceptId): void
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM pages WHERE user_id = ? AND archived_at IS NULL AND lower(title) = lower(?)',
            [$userId, $title]
        );
        if ($row !== null && (int) $row['id'] !== $exceptId) {
            throw ValidationException::withFields(['title' => 'A page with that title already exists.']);
        }
    }

    private function uniqueSlug(int $userId, string $title): string
    {
        $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
        $base = $base !== '' ? $base : 'page';
        $base = substr($base, 0, 180);
        $slug = $base;
        $n = 2;
        while ($this->db->fetchOne('SELECT 1 FROM pages WHERE user_id = ? AND slug = ?', [$userId, $slug]) !== null) {
            $slug = $base . '-' . $n;
            $n++;
        }
        return $slug;
    }

    private function cleanTitle(mixed $title): string
    {
        $title = trim((string) $title);
        if ($title === '' || mb_strlen($title) > 200) {
            throw ValidationException::withFields(['title' => 'Title must be 1–200 characters.']);
        }
        return $title;
    }

    private function cleanKind(mixed $kind): string
    {
        $kind = (string) $kind;
        if (!in_array($kind, self::KINDS, true)) {
            throw ValidationException::withFields(['kind' => 'Kind must be one of: ' . implode(', ', self::KINDS) . '.']);
        }
        return $kind;
    }

    private function cleanBody(mixed $body): string
    {
        $body = (string) $body;
        if (mb_strlen($body) > self::MAX_BODY) {
            throw ValidationException::withFields(['body' => 'Page is too long.']);
        }
        return $body;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function brief(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'title'      => (string) $row['title'],
            'slug'       => (string) $row['slug'],
            'kind'       => (string) $row['kind'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function full(array $row): array
    {
        return $this->brief($row) + [
            'body'     => (string) $row['body'],
            'archived' => $row['archived_at'] !== null,
        ];
    }

    /**
     * @return array<int, array{x: float, y: float, cluster_key: string}>
     */
    private function savedLayout(int $userId): array
    {
        $saved = [];
        foreach ($this->db->fetchAll(
            'SELECT page_id, cluster_key, x, y FROM page_graph_nodes WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $saved[(int) $row['page_id']] = [
                'x'           => (float) $row['x'],
                'y'           => (float) $row['y'],
                'cluster_key' => (string) $row['cluster_key'],
            ];
        }
        return $saved;
    }

    /**
     * @param list<array{id: int, cluster_key: string, x: float, y: float}> $dirty
     */
    private function saveLayout(int $userId, array $dirty): void
    {
        foreach ($dirty as $node) {
            $this->db->execute(
                'INSERT INTO page_graph_nodes (page_id, user_id, cluster_key, x, y)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (page_id) DO UPDATE
                    SET cluster_key = EXCLUDED.cluster_key, x = EXCLUDED.x, y = EXCLUDED.y,
                        updated_at = (now() AT TIME ZONE \'UTC\')',
                [$node['id'], $userId, $node['cluster_key'], $node['x'], $node['y']]
            );
        }
    }

    private function pruneLayout(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM page_graph_nodes AS g
             WHERE g.user_id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM pages AS p
                   WHERE p.id = g.page_id AND p.user_id = g.user_id AND p.archived_at IS NULL
               )',
            [$userId]
        );
    }

    /**
     * @return array<int, list<float>>
     */
    private function embeddings(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT page_id, embedding FROM page_embeddings WHERE user_id = ?',
            [$userId]
        );
        if (count($rows) > 120) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            $vec = is_string($row['embedding']) ? json_decode($row['embedding'], true) : $row['embedding'];
            if (!is_array($vec) || $vec === []) {
                continue;
            }
            $out[(int) $row['page_id']] = array_map(static fn ($n): float => (float) $n, $vec);
        }
        return $out;
    }
}
