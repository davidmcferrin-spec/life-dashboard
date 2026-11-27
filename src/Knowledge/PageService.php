<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

use Dashboard\Audit\AuditLog;
use Dashboard\DB\Database;
use Dashboard\Ollama\OllamaClient;
use Dashboard\Ollama\OllamaSettings;
use Dashboard\Support\ConflictException;
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
    public function list(
        int $userId,
        ?string $query = null,
        int $limit = 100,
        ?int $folderId = null,
        bool $folderSet = false,
        bool $includeArchived = false,
        ?string $updatedSince = null,
    ): array {
        $limit = max(1, min(200, $limit));
        $query = $query !== null ? trim($query) : '';
        [$clause, $folderParams] = $this->folderClause($userId, $folderId, $folderSet, 'folder_id');
        $live = $includeArchived ? '' : ' AND archived_at IS NULL';
        $sinceSql = '';
        $sinceParams = [];
        if ($updatedSince !== null && trim($updatedSince) !== '') {
            $sinceSql = ' AND updated_at >= ?';
            $sinceParams[] = PageQuery::since($updatedSince);
        }
        $columns = 'id, title, slug, kind, folder_id, parent_id, favorited_at, updated_at, updated_by, archived_at';
        if ($query === '') {
            return array_map(
                fn (array $row): array => $this->brief($row),
                $this->db->fetchAll(
                    "SELECT {$columns} FROM pages
                     WHERE user_id = ?{$live}{$clause}{$sinceSql}
                     ORDER BY updated_at DESC, id DESC LIMIT {$limit}",
                    array_merge([$userId], $folderParams, $sinceParams)
                )
            );
        }

        $like = mb_strlen($query) >= 3
            ? '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query) . '%'
            : null;
        $phraseSql = $like === null
            ? ''
            : ' OR title ILIKE ? OR body ILIKE ?';
        $phraseParams = $like === null ? [] : [$like, $like];
        $orderPhrase = $like === null
            ? ''
            : ' WHEN title ILIKE ? THEN 2 WHEN body ILIKE ? THEN 1';
        $orderParams = array_merge([$query], $phraseParams);
        $fts = $this->db->fetchAll(
            "SELECT {$columns}, body
             FROM pages
             WHERE user_id = ?{$live}{$clause}{$sinceSql}
               AND (
                 to_tsvector('english', title || ' ' || body) @@ plainto_tsquery('english', ?)
                 OR lower(title) = lower(?)
                 {$phraseSql}
               )
             ORDER BY CASE WHEN lower(title) = lower(?) THEN 3{$orderPhrase} ELSE 0 END DESC, updated_at DESC
             LIMIT 200",
            array_merge([$userId], $folderParams, $sinceParams, [$query, $query], $phraseParams, $orderParams)
        );
        $byId = [];
        foreach ($fts as $row) {
            $id = (int) $row['id'];
            $row['former'] = [];
            $row['lexical'] = true;
            $byId[$id] = $row;
        }
        foreach ($this->aliasHits($userId, $query, $like) as $row) {
            if (!$this->rowVisible($row, $includeArchived, $folderSet, $folderId, $folderParams, $sinceParams[0] ?? null)) {
                continue;
            }
            $id = (int) $row['id'];
            $former = (string) $row['former_title'];
            if (!isset($byId[$id])) {
                $row['former'] = [$former];
                $row['lexical'] = true;
                $byId[$id] = $row;
                continue;
            }
            $byId[$id]['former'][] = $former;
        }
        $semantic = $this->semantic($userId, $query, $limit, $includeArchived);
        if ($folderSet) {
            $allowed = array_flip($folderParams);
            $semantic = array_values(array_filter(
                $semantic,
                static function (array $row) use ($folderId, $allowed): bool {
                    $id = $row['folder_id'] ?? null;
                    if ($folderId === null && $folderParams === []) {
                        return $id === null;
                    }
                    return $id !== null && isset($allowed[(int) $id]);
                }
            ));
        }
        foreach ($semantic as $row) {
            if ($sinceParams !== [] && (string) $row['updated_at'] < $sinceParams[0]) {
                continue;
            }
            $id = (int) $row['id'];
            if (isset($byId[$id])) {
                continue;
            }
            $row['body'] = '';
            $row['former'] = [];
            $row['lexical'] = false;
            $byId[$id] = $row;
        }
        $ranked = [];
        foreach ($byId as $row) {
            $row['rank'] = PageRank::score(
                $query,
                (string) $row['title'],
                (string) ($row['body'] ?? ''),
                $row['former'] ?? [],
                (bool) ($row['lexical'] ?? true)
            );
            $ranked[] = $row;
        }
        usort($ranked, static function (array $a, array $b): int {
            if ($a['rank'] !== $b['rank']) {
                return $b['rank'] <=> $a['rank'];
            }
            $time = strcmp((string) $b['updated_at'], (string) $a['updated_at']);
            if ($time !== 0) {
                return $time;
            }
            return $b['id'] <=> $a['id'];
        });
        return array_map(
            fn (array $row): array => $this->brief($row),
            array_slice($ranked, 0, $limit)
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $userId, int $limit = 8): array
    {
        return $this->list($userId, null, $limit);
    }

    /**
     * Vault Recent: the later of an edit and an open, newest first.
     * A bot edit counts even when nobody has opened the page. Archived pages stay out.
     * A title that still says "(archived)" is left as written; archived_at is the flag.
     *
     * @return list<array<string, mixed>>
     */
    public function opened(int $userId, int $limit = 12): array
    {
        $limit = max(1, min(50, $limit));
        $rows = $this->db->fetchAll(
            "SELECT id, title, slug, kind, folder_id, parent_id, favorited_at, updated_at, updated_by, archived_at, last_opened_at,
                    GREATEST(updated_at, COALESCE(last_opened_at, updated_at)) AS activity_at
             FROM pages
             WHERE user_id = ? AND archived_at IS NULL
             ORDER BY activity_at DESC, COALESCE(last_opened_at, TIMESTAMP '1970-01-01') DESC, updated_at DESC, id DESC
             LIMIT {$limit}",
            [$userId]
        );
        return array_map(fn (array $row): array => $this->brief($row), $rows);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function favorites(int $userId): array
    {
        return array_map(
            fn (array $row): array => $this->brief($row),
            $this->db->fetchAll(
                'SELECT id, title, slug, kind, folder_id, parent_id, favorited_at, updated_at, updated_by, archived_at
                 FROM pages
                 WHERE user_id = ? AND archived_at IS NULL AND favorited_at IS NOT NULL
                 ORDER BY favorited_at DESC, id DESC',
                [$userId]
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $id): array
    {
        $this->ensureProseLinks($userId);
        $row = $this->db->fetchOne('SELECT * FROM pages WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Page not found.');
        }
        $page = $this->full($row);
        $page['former_titles'] = $this->formerTitles($userId, $id);
        $page['backlinks'] = $this->backlinks($userId, $id);
        $page['links'] = $this->outgoing($userId, $id);
        $page['revisions'] = $this->revisions($userId, $id);
        $page['version'] = isset($page['revisions'][0]['version']) ? (int) $page['revisions'][0]['version'] : 0;
        $page['breadcrumbs'] = $this->breadcrumbs($userId, $page['folder_id']);
        $page['parents'] = $this->ancestors($userId, $page['parent_id']);
        $page['children'] = $this->childRows($userId, $id);
        $page['mentions'] = $this->mentions($userId, $id, (string) $page['title']);
        return $page;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $title = $this->cleanTitle($input['title'] ?? null);
        $this->assertUniqueTitle($userId, $title, null);
        $kind = $this->cleanKind($input['kind'] ?? 'note');
        $body = $this->cleanBody($input['body'] ?? '');
        $slug = $this->uniqueSlug($userId, $title);
        $parentId = $this->parentFromInput($userId, $input);
        $folderId = $this->inputHasFolder($input) ? $this->folderFromInput($userId, $input) : null;
        if ($parentId !== null && !$this->inputHasFolder($input)) {
            $parent = $this->requireLivePage($userId, $parentId);
            $folderId = $parent['folder_id'];
        }
        $editor = $this->editorLabel($editor, $actor);

        $id = (int) $this->db->fetchScalar(
            'INSERT INTO pages (user_id, title, slug, kind, body, folder_id, parent_id, updated_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$userId, $title, $slug, $kind, $body, $folderId, $parentId, $editor]
        );
        $this->addRevision($userId, $id, 1, $title, $body);
        $this->syncLinks($userId, $id, $body);
        $this->relink($userId);
        $this->maybeEmbed($userId, $id, $title, $body);
        $this->audit->record($userId, $actor, $tool, 'page.create', 'page:' . $id, ['title' => $title]);
        $page = $this->get($userId, $id);
        if ($folderId === null) {
            $page['suggested_folder'] = $this->suggestedFolder($userId, $title);
        }
        return $page;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $userId, int $id, array $input, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $checked = $this->expectation($input);
        $run = function () use ($userId, $id, $input, $actor, $tool, $editor, $checked): array {
            if ($checked !== null) {
                $this->lockPage($userId, $id);
                $this->assertFresh($userId, $id, $checked);
            }

            return $this->commitUpdate($userId, $id, $input, $actor, $tool, $editor, $checked === null);
        };
        $saved = $checked !== null ? $this->db->transaction($run) : $run();
        if ($checked !== null && $saved['changed']) {
            $page = $saved['page'];
            $this->maybeEmbed($userId, (int) $page['id'], (string) $page['title'], (string) $page['body']);
        }

        return $saved['page'];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{page: array<string, mixed>, changed: bool}
     */
    private function commitUpdate(int $userId, int $id, array $input, string $actor, ?string $tool, ?string $editor, bool $embed): array
    {
        $page = $this->get($userId, $id);
        $oldTitle = (string) $page['title'];
        $title = array_key_exists('title', $input) ? $this->cleanTitle($input['title']) : $oldTitle;
        $kind  = array_key_exists('kind', $input) ? $this->cleanKind($input['kind']) : (string) $page['kind'];
        $body  = array_key_exists('body', $input) ? $this->cleanBody($input['body']) : (string) $page['body'];
        if ($title !== $oldTitle) {
            $this->assertUniqueTitle($userId, $title, $id);
            $body = Wikilinks::rewrite($body, $oldTitle, $title);
        }
        if ($this->inputHasFolder($input)) {
            $this->movePage($userId, $id, $this->folderFromInput($userId, $input), $actor, $tool, $editor);
            $page = $this->get($userId, $id);
        }
        if ($title === $page['title'] && $kind === $page['kind'] && $body === $page['body']) {
            return ['page' => $page, 'changed' => false];
        }
        $editor = $this->editorLabel($editor, $actor);
        $version = (int) $this->db->fetchScalar('SELECT COALESCE(MAX(version), 0) + 1 FROM page_revisions WHERE page_id = ?', [$id]);
        $this->db->execute(
            "UPDATE pages SET title = ?, kind = ?, body = ?, updated_by = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$title, $kind, $body, $editor, $id, $userId]
        );
        $this->addRevision($userId, $id, $version, $title, $body);
        $this->syncLinks($userId, $id, $body);
        if ($title !== $oldTitle) {
            $this->rememberTitle($userId, $id, $oldTitle, (string) $page['slug']);
            $this->renameIncoming($userId, $id, $oldTitle, $title, $actor, $tool);
        }
        $this->relink($userId);
        if ($embed) {
            $this->maybeEmbed($userId, $id, $title, $body);
        }
        $this->audit->record($userId, $actor, $tool, 'page.update', 'page:' . $id, ['title' => $title]);
        return ['page' => $this->get($userId, $id), 'changed' => true];
    }

    /**
     * Create or replace a page with this slug. Used by the weekly money review.
     *
     * @return array<string, mixed>
     */
    public function upsertSlug(int $userId, string $slug, string $title, string $body, string $kind, string $actor = 'user', ?string $editor = null): array
    {
        $row = $this->db->fetchOne('SELECT id FROM pages WHERE user_id = ? AND slug = ?', [$userId, $slug]);
        if ($row === null) {
            $title = $this->cleanTitle($title);
            $body  = $this->cleanBody($body);
            $kind  = $this->cleanKind($kind);
            $label = $this->editorLabel($editor, $actor);
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO pages (user_id, title, slug, kind, body, updated_by) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [$userId, $title, $slug, $kind, $body, $label]
            );
            $this->addRevision($userId, $id, 1, $title, $body);
            $this->syncLinks($userId, $id, $body);
            $this->relink($userId);
            $this->maybeEmbed($userId, $id, $title, $body);
            $this->audit->record($userId, $actor, null, 'page.create', 'page:' . $id, ['title' => $title]);
            return $this->get($userId, $id);
        }
        return $this->update($userId, (int) $row['id'], ['title' => $title, 'body' => $body, 'kind' => $kind], $actor, null, $editor);
    }

    /**
     * @return array<string, mixed>
     */
    public function restore(int $userId, int $id, int $version, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $this->get($userId, $id);
        $rev = $this->db->fetchOne(
            'SELECT title, body FROM page_revisions WHERE page_id = ? AND user_id = ? AND version = ?',
            [$id, $userId, $version]
        );
        if ($rev === null) {
            throw new NotFoundException('Revision not found.');
        }
        return $this->update($userId, $id, ['title' => $rev['title'], 'body' => $rev['body']], $actor, $tool, $editor);
    }

    /**
     * Redline one saved revision against the current page. Title and body are
     * one source string so a title-only edit shows up.
     *
     * @return array{revision: array{version: int, title: string, body: string, created_at: string}, diff: array{html: string, additions: int, deletions: int, grain: string, summary: string}}
     */
    public function revisionDiff(int $userId, int $id, int $version): array
    {
        $page = $this->get($userId, $id);
        $rev = $this->db->fetchOne(
            'SELECT version, title, body, created_at FROM page_revisions WHERE page_id = ? AND user_id = ? AND version = ?',
            [$id, $userId, $version]
        );
        if ($rev === null) {
            throw new NotFoundException('Revision not found.');
        }
        $before = (string) $rev['title'] . "\n\n" . (string) $rev['body'];
        $after = (string) $page['title'] . "\n\n" . (string) $page['body'];
        return [
            'revision' => [
                'version'    => (int) $rev['version'],
                'title'      => (string) $rev['title'],
                'body'       => (string) $rev['body'],
                'created_at' => (string) $rev['created_at'],
            ],
            'diff' => Redline::compare($before, $after),
        ];
    }

    public function archive(int $userId, int $id, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $page = $this->get($userId, $id);
        if ($page['archived'] === true) {
            return $page;
        }
        $parentId = $page['parent_id'];
        $label = $this->editorLabel($editor, $actor);
        $this->db->execute(
            'UPDATE pages SET parent_id = ? WHERE user_id = ? AND parent_id = ? AND archived_at IS NULL',
            [$parentId, $userId, $id]
        );
        $this->db->execute(
            "UPDATE pages SET archived_at = (now() AT TIME ZONE 'UTC'), updated_by = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$label, $id, $userId]
        );
        $this->audit->record($userId, $actor, $tool, 'page.archive', 'page:' . $id);
        return $this->get($userId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function archiveIdentified(int $userId, array $input, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $page = $this->find($userId, $this->optionalPageId($input), $this->optionalString($input, 'title'), $this->optionalString($input, 'slug'));
        return $this->archive($userId, (int) $page['id'], $actor, $tool, $editor);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function unarchiveIdentified(int $userId, array $input, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $page = $this->find($userId, $this->optionalPageId($input), $this->optionalString($input, 'title'), $this->optionalString($input, 'slug'));
        return $this->unarchive($userId, (int) $page['id'], $actor, $tool, $editor);
    }

    /**
     * @return array<string, mixed>
     */
    public function unarchive(int $userId, int $id, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $page = $this->get($userId, $id);
        if ($page['archived'] !== true) {
            return $page;
        }
        $label = $this->editorLabel($editor, $actor);
        $this->db->execute(
            "UPDATE pages SET archived_at = NULL, updated_by = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$label, $id, $userId]
        );
        $this->audit->record($userId, $actor, $tool, 'page.unarchive', 'page:' . $id);
        return $this->get($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function find(int $userId, ?int $id, ?string $title, ?string $slug): array
    {
        if ($id !== null) {
            return $this->get($userId, $id);
        }
        $title = trim((string) $title);
        $slug = trim((string) $slug);
        if ($title === '' && $slug === '') {
            throw ValidationException::withFields(['title' => 'Pass page_id, title, or slug.']);
        }
        if ($title !== '') {
            $live = $this->db->fetchOne(
                'SELECT id FROM pages WHERE user_id = ? AND archived_at IS NULL AND lower(title) = lower(?)',
                [$userId, $title]
            );
            if ($live !== null) {
                return $this->get($userId, (int) $live['id']);
            }
        }
        if ($slug !== '') {
            $bySlug = $this->db->fetchOne(
                'SELECT id FROM pages WHERE user_id = ? AND slug = ?',
                [$userId, $slug]
            );
            if ($bySlug !== null) {
                return $this->get($userId, (int) $bySlug['id']);
            }
        }
        $needle = $title !== '' ? $title : $slug;
        $seen = [];
        foreach ($this->db->fetchAll(
            "SELECT p.id, p.title
             FROM page_titles t
             JOIN pages p ON p.id = t.page_id AND p.user_id = t.user_id
             WHERE t.user_id = ? AND (lower(t.title) = lower(?) OR lower(COALESCE(t.slug, '')) = lower(?))
             ORDER BY t.created_at DESC, t.id DESC",
            [$userId, $needle, $needle]
        ) as $row) {
            $seen[(int) $row['id']] = (string) $row['title'];
        }
        if ($title !== '') {
            foreach ($this->db->fetchAll(
                'SELECT id, title FROM pages WHERE user_id = ? AND archived_at IS NOT NULL AND lower(title) = lower(?)',
                [$userId, $title]
            ) as $row) {
                $seen[(int) $row['id']] = (string) $row['title'];
            }
        }
        if (count($seen) === 1) {
            return $this->get($userId, (int) array_key_first($seen));
        }
        if (count($seen) > 1) {
            $bits = [];
            foreach ($seen as $pageId => $current) {
                $bits[] = $pageId . ' ' . $current;
            }
            throw ValidationException::withFields([
                'title' => 'That title matches more than one page. Pass page_id: ' . implode(', ', $bits) . '.',
            ]);
        }
        throw new NotFoundException('Page not found.');
    }

    /**
     * Append, prepend, or change one section, then save a revision the same way a full write does.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function patch(int $userId, array $input, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $page = $this->find(
            $userId,
            $this->optionalPageId($input),
            $this->optionalString($input, 'title'),
            $this->optionalString($input, 'slug')
        );
        $body = PagePatch::apply(
            (string) $page['body'],
            (string) ($input['mode'] ?? ''),
            (string) ($input['text'] ?? ''),
            isset($input['heading']) ? (string) $input['heading'] : null,
            isset($input['position']) ? (string) $input['position'] : 'end'
        );
        return $this->update($userId, (int) $page['id'], ['body' => $body], $actor, $tool, $editor);
    }

    /**
     * Insert text under a heading, or at the end, while the page row is locked.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function append(int $userId, array $input, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $page = $this->find(
            $userId,
            $this->optionalPageId($input),
            $this->optionalString($input, 'title'),
            $this->optionalString($input, 'slug')
        );
        $id = (int) $page['id'];
        $text = (string) ($input['text'] ?? '');
        $heading = $input['under_heading'] ?? $input['heading'] ?? null;
        $heading = $heading === null ? null : trim((string) $heading);
        if ($heading === '') {
            $heading = null;
        }
        $saved = $this->db->transaction(function () use ($userId, $id, $text, $heading, $actor, $tool, $editor): array {
            $locked = $this->lockPage($userId, $id);
            $body = PagePatch::apply(
                (string) $locked['body'],
                $heading === null ? 'append' : 'insert_section',
                $text,
                $heading,
                'end'
            );

            return $this->commitUpdate($userId, $id, ['body' => $body], $actor, $tool, $editor, false);
        });
        if ($saved['changed']) {
            $written = $saved['page'];
            $this->maybeEmbed($userId, (int) $written['id'], (string) $written['title'], (string) $written['body']);
        }

        return $saved['page'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function backlinksFor(int $userId, int $id): array
    {
        $this->ensureProseLinks($userId);
        $this->requireLivePage($userId, $id);
        return $this->backlinks($userId, $id);
    }

    /**
     * Drop page_links that exist only because a wikilink sat in code.
     * Page bodies and revisions are not written.
     */
    public function repairProseLinks(int $userId): int
    {
        $changed = 0;
        $rows = $this->db->fetchAll('SELECT id, body FROM pages WHERE user_id = ?', [$userId]);
        foreach ($rows as $row) {
            $want = [];
            foreach (Wikilinks::titles((string) $row['body']) as $title) {
                $want[mb_strtolower($title)] = true;
            }
            $have = [];
            foreach ($this->db->fetchAll(
                'SELECT to_title FROM page_links WHERE user_id = ? AND from_page_id = ?',
                [$userId, (int) $row['id']]
            ) as $link) {
                $have[mb_strtolower((string) $link['to_title'])] = true;
            }
            if ($want === $have) {
                continue;
            }
            $this->syncLinks($userId, (int) $row['id'], (string) $row['body']);
            $changed++;
        }
        if ($changed > 0) {
            $this->relink($userId);
        }
        return $changed;
    }

    public function ensureProseLinks(int $userId): void
    {
        $flag = $this->db->fetchOne(
            "SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = 'vault_link_index'",
            [$userId]
        );
        if ($flag !== null && (string) $flag['pref_value'] === '2') {
            return;
        }
        $this->repairProseLinks($userId);
        $this->db->execute(
            "INSERT INTO user_preferences (user_id, pref_key, pref_value) VALUES (?, 'vault_link_index', '2')
             ON CONFLICT (user_id, pref_key) DO UPDATE
                SET pref_value = '2', updated_at = (now() AT TIME ZONE 'UTC')",
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function children(int $userId, int $id): array
    {
        $this->requireLivePage($userId, $id);
        return $this->childRows($userId, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mentionsFor(int $userId, int $id): array
    {
        $page = $this->requireLivePage($userId, $id);
        return $this->mentions($userId, $id, (string) $page['title']);
    }

    /**
     * @return array<string, mixed>
     */
    public function setParent(int $userId, int $id, ?int $parentId, string $actor = 'user', ?string $tool = null, ?string $editor = null): array
    {
        $page = $this->requireLivePage($userId, $id);
        if ($parentId === $id) {
            throw ValidationException::withFields(['parent_id' => 'A page cannot be its own parent.']);
        }
        $folderId = $page['folder_id'];
        if ($parentId !== null) {
            $parent = $this->requireLivePage($userId, $parentId);
            $this->assertPageNesting($userId, $id, $parentId);
            $folderId = $parent['folder_id'];
        }
        $current = $page['parent_id'];
        if ($current === $parentId && $page['folder_id'] === $folderId) {
            return $this->get($userId, $id);
        }
        $label = $this->editorLabel($editor, $actor);
        $this->db->execute(
            "UPDATE pages SET parent_id = ?, folder_id = ?, updated_by = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$parentId, $folderId, $label, $id, $userId]
        );
        $this->audit->record($userId, $actor, $tool, 'page.parent', 'page:' . $id, [
            'parent_id' => $parentId,
        ]);
        return $this->get($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function setFavorite(int $userId, int $id, bool $on, string $actor = 'user', ?string $tool = null): array
    {
        $this->requireLivePage($userId, $id);
        if ($on) {
            $this->db->execute(
                "UPDATE pages SET favorited_at = COALESCE(favorited_at, (now() AT TIME ZONE 'UTC')) WHERE id = ? AND user_id = ?",
                [$id, $userId]
            );
        } else {
            $this->db->execute(
                'UPDATE pages SET favorited_at = NULL WHERE id = ? AND user_id = ?',
                [$id, $userId]
            );
        }
        $this->audit->record($userId, $actor, $tool, $on ? 'page.favorite' : 'page.unfavorite', 'page:' . $id);
        return $this->get($userId, $id);
    }

    public function touch(int $userId, int $id): void
    {
        $this->requireLivePage($userId, $id);
        $this->db->execute(
            "UPDATE pages SET last_opened_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    /**
     * @return array{title: string, heading: ?string, alias: ?string, page_id: ?int, resolved_title: ?string}
     */
    public function resolveLink(int $userId, string $raw): array
    {
        $parsed = Wikilinks::parse($raw);
        $row = $this->db->fetchOne(
            'SELECT id, title FROM pages WHERE user_id = ? AND archived_at IS NULL AND lower(title) = lower(?)',
            [$userId, $parsed['title']]
        );
        return [
            'title'           => $parsed['title'],
            'heading'         => $parsed['heading'],
            'alias'           => $parsed['alias'],
            'page_id'         => $row !== null ? (int) $row['id'] : null,
            'resolved_title'  => $row !== null ? (string) $row['title'] : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function folderTree(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, parent_id, name FROM page_folders WHERE user_id = ? ORDER BY lower(name) ASC, id ASC',
            [$userId]
        );
        $byParent = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : 0;
            $byParent[$parent][] = $row;
        }
        $walk = function (int $parent) use (&$walk, $byParent): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $row) {
                $id = (int) $row['id'];
                $out[] = [
                    'id'        => $id,
                    'name'      => (string) $row['name'],
                    'parent_id' => $parent === 0 ? null : $parent,
                    'children'  => $walk($id),
                ];
            }
            return $out;
        };
        return $walk(0);
    }

    /**
     * @return array<string, mixed>
     */
    public function createFolder(int $userId, string $name, ?int $parentId, string $actor = 'user', ?string $tool = null): array
    {
        $name = $this->cleanFolderName($name);
        if ($parentId !== null) {
            $this->requireFolder($userId, $parentId);
            $this->assertDepth($userId, $parentId);
        }
        try {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO page_folders (user_id, parent_id, name) VALUES (?, ?, ?) RETURNING id',
                [$userId, $parentId, $name]
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'page_folders_sibling_name') || str_contains($e->getMessage(), '23505')) {
                throw ValidationException::withFields(['name' => 'A folder with that name is already here.']);
            }
            throw $e;
        }
        $this->audit->record($userId, $actor, $tool, 'folder.create', 'folder:' . $id, ['folder_id' => $id, 'parent_id' => $parentId]);
        return $this->folderRow($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function renameFolder(int $userId, int $id, string $name, string $actor = 'user', ?string $tool = null): array
    {
        $this->requireFolder($userId, $id);
        $name = $this->cleanFolderName($name);
        try {
            $this->db->execute(
                "UPDATE page_folders SET name = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
                [$name, $id, $userId]
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), '23505')) {
                throw ValidationException::withFields(['name' => 'A folder with that name is already here.']);
            }
            throw $e;
        }
        $this->audit->record($userId, $actor, $tool, 'folder.rename', 'folder:' . $id, ['folder_id' => $id]);
        return $this->folderRow($userId, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function moveFolder(int $userId, int $id, ?int $parentId, string $actor = 'user', ?string $tool = null): array
    {
        $folder = $this->requireFolder($userId, $id);
        if ($parentId !== null) {
            $this->requireFolder($userId, $parentId);
        }
        if ($parentId === $id) {
            throw ValidationException::withFields(['parent_id' => 'A folder cannot sit inside itself.']);
        }
        $this->assertNotDescendant($userId, $id, $parentId);
        if ($parentId !== null) {
            $this->assertDepth($userId, $parentId, $this->subtreeHeight($userId, $id));
        }
        $current = $folder['parent_id'];
        if ($current === $parentId) {
            return $this->folderRow($userId, $id);
        }
        try {
            $this->db->execute(
                "UPDATE page_folders SET parent_id = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
                [$parentId, $id, $userId]
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), '23505')) {
                throw ValidationException::withFields(['parent_id' => 'A folder with that name is already there.']);
            }
            throw $e;
        }
        $this->audit->record($userId, $actor, $tool, 'folder.move', 'folder:' . $id, ['folder_id' => $id, 'parent_id' => $parentId]);
        return $this->folderRow($userId, $id);
    }

    public function deleteFolder(int $userId, int $id, bool $moveContents, string $actor = 'user', ?string $tool = null): void
    {
        $folder = $this->requireFolder($userId, $id);
        $parentId = $folder['parent_id'];
        $children = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM page_folders WHERE user_id = ? AND parent_id = ?', [$userId, $id]);
        $pages = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM pages WHERE user_id = ? AND folder_id = ?', [$userId, $id]);
        if (!$moveContents && ($children > 0 || $pages > 0)) {
            throw ValidationException::withFields(['folder' => 'Folder is not empty. Move its contents to the parent, or remove them first.']);
        }
        try {
            $this->db->transaction(function () use ($userId, $id, $parentId, $moveContents): void {
                if ($moveContents) {
                    $this->db->execute(
                        'UPDATE page_folders SET parent_id = ?, updated_at = (now() AT TIME ZONE \'UTC\') WHERE user_id = ? AND parent_id = ?',
                        [$parentId, $userId, $id]
                    );
                    $this->db->execute(
                        "UPDATE pages SET folder_id = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE user_id = ? AND folder_id = ?",
                        [$parentId, $userId, $id]
                    );
                }
                $this->db->execute('DELETE FROM page_folders WHERE id = ? AND user_id = ?', [$id, $userId]);
            });
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), '23505')) {
                throw ValidationException::withFields(['folder' => 'A folder with that name is already in the parent.']);
            }
            throw $e;
        }
        $this->audit->record($userId, $actor, $tool, 'folder.delete', 'folder:' . $id, ['folder_id' => $id, 'parent_id' => $parentId]);
    }

    public function movePage(int $userId, int $pageId, ?int $folderId, string $actor = 'user', ?string $tool = null, ?string $editor = null): void
    {
        $page = $this->get($userId, $pageId);
        if ($folderId !== null) {
            $this->requireFolder($userId, $folderId);
        }
        $current = $page['folder_id'];
        if ($current === $folderId) {
            return;
        }
        $label = $this->editorLabel($editor, $actor);
        $this->db->execute(
            "UPDATE pages SET folder_id = ?, updated_by = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$folderId, $label, $pageId, $userId]
        );
        $this->audit->record($userId, $actor, $tool, 'page.move', 'page:' . $pageId, ['page_id' => $pageId, 'folder_id' => $folderId]);
    }

    /**
     * A single name resolves when it is unique anywhere in the tree.
     * A path with slashes still starts at the root. Ambiguous and missing
     * names include full paths so the caller can retry.
     */
    public function folderIdByPath(int $userId, string $path): int
    {
        $parts = array_values(array_filter(
            array_map('trim', explode('/', str_replace('\\', '/', $path))),
            static fn (string $part): bool => $part !== ''
        ));
        if ($parts === []) {
            throw ValidationException::withFields(['folder' => 'Folder path is empty.']);
        }
        $catalog = $this->folderCatalog($userId);
        if (count($parts) === 1) {
            $matches = array_values(array_filter(
                $catalog,
                static fn (array $folder): bool => strcasecmp($folder['name'], $parts[0]) === 0
            ));
            if (count($matches) === 1) {
                return (int) $matches[0]['id'];
            }
            if (count($matches) > 1) {
                throw ValidationException::withFields([
                    'folder' => $this->folderPathMessage($parts[0], array_column($matches, 'path'), true),
                ]);
            }
            throw new NotFoundException($this->folderPathMessage($parts[0], $this->suggestPaths($catalog, $parts[0]), false));
        }
        $parent = null;
        $id = 0;
        foreach ($parts as $part) {
            $found = null;
            foreach ($catalog as $folder) {
                if ($folder['parent_id'] === $parent && strcasecmp($folder['name'], $part) === 0) {
                    $found = $folder;
                    break;
                }
            }
            if ($found === null) {
                throw new NotFoundException($this->folderPathMessage($part, $this->suggestPaths($catalog, $parts[array_key_last($parts)]), false));
            }
            $id = (int) $found['id'];
            $parent = $id;
        }
        return $id;
    }

    /**
     * The folder itself, plus its own pages and child folders. An empty folder still returns the folder.
     *
     * @return array{folder: array<string, mixed>, pages: list<array<string, mixed>>, folders: list<array<string, mixed>>}
     */
    public function folderContents(int $userId, ?int $folderId, bool $includeArchived = false): array
    {
        $catalog = $this->folderCatalog($userId);
        $folder = [
            'id'        => null,
            'name'      => 'root',
            'parent_id' => null,
            'path'      => '',
        ];
        if ($folderId !== null) {
            $this->requireFolder($userId, $folderId);
            $match = null;
            foreach ($catalog as $row) {
                if ($row['id'] === $folderId) {
                    $match = $row;
                    break;
                }
            }
            if ($match === null) {
                throw new NotFoundException('Folder not found.');
            }
            $folder = [
                'id'        => $match['id'],
                'name'      => $match['name'],
                'parent_id' => $match['parent_id'],
                'path'      => $match['path'],
            ];
        }
        $live = $includeArchived ? '' : ' AND archived_at IS NULL';
        $columns = 'id, title, slug, kind, folder_id, parent_id, favorited_at, updated_at, updated_by, archived_at';
        if ($folderId === null) {
            $pages = $this->db->fetchAll(
                "SELECT {$columns} FROM pages WHERE user_id = ? AND folder_id IS NULL{$live} ORDER BY lower(title) ASC, id ASC",
                [$userId]
            );
        } else {
            $pages = $this->db->fetchAll(
                "SELECT {$columns} FROM pages WHERE user_id = ? AND folder_id = ?{$live} ORDER BY lower(title) ASC, id ASC",
                [$userId, $folderId]
            );
        }
        $children = [];
        foreach ($catalog as $row) {
            if ($row['parent_id'] === $folderId) {
                $children[] = [
                    'id'        => $row['id'],
                    'name'      => $row['name'],
                    'parent_id' => $row['parent_id'],
                    'path'      => $row['path'],
                ];
            }
        }
        usort($children, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return [
            'folder'  => $folder,
            'pages'   => array_map(fn (array $row): array => $this->brief($row), $pages),
            'folders' => $children,
        ];
    }

    /**
     * @return array{id: ?int, name: string, parent_id: ?int, path: string}
     */
    public function folderSummary(int $userId, ?int $folderId): array
    {
        if ($folderId === null) {
            return ['id' => null, 'name' => 'root', 'parent_id' => null, 'path' => ''];
        }
        foreach ($this->folderCatalog($userId) as $row) {
            if ($row['id'] === $folderId) {
                return [
                    'id'        => $row['id'],
                    'name'      => $row['name'],
                    'parent_id' => $row['parent_id'],
                    'path'      => $row['path'],
                ];
            }
        }
        throw new NotFoundException('Folder not found.');
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
        $this->ensureProseLinks($userId);
        $pages = [];
        $meta = [];
        foreach ($this->db->fetchAll(
            'SELECT p.id, p.title, p.kind, p.folder_id, p.updated_at, f.name AS folder_name
             FROM pages p
             LEFT JOIN page_folders f ON f.id = p.folder_id AND f.user_id = p.user_id
             WHERE p.user_id = ? AND p.archived_at IS NULL
             ORDER BY p.id ASC',
            [$userId]
        ) as $row) {
            $id = (int) $row['id'];
            $pages[] = [
                'id'    => $id,
                'title' => (string) $row['title'],
                'kind'  => (string) $row['kind'],
            ];
            $folderId = $row['folder_id'];
            $meta[$id] = [
                'folder_id'  => $folderId !== null && $folderId !== '' ? (int) $folderId : null,
                'folder'     => $row['folder_name'] !== null && $row['folder_name'] !== '' ? (string) $row['folder_name'] : null,
                'updated_at' => (string) $row['updated_at'],
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
            $extra = $meta[$node['id']] ?? ['folder_id' => null, 'folder' => null, 'updated_at' => ''];
            $node['folder_id'] = $extra['folder_id'];
            $node['folder'] = $extra['folder'];
            $node['updated_at'] = $extra['updated_at'];
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
     * Rolling page reads and writes for the mesh panel. No titles.
     *
     * @return list<array{id: int, page_id: int, verb: string, actor: string, source: string, at: string}>
     */
    public function graphAccess(int $userId, int $limit = 80): array
    {
        return BrainActivity::signals($this->audit->pageEvents($userId, $limit));
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

    /**
     * @param array<string, mixed> $input
     */
    private function parentFromInput(int $userId, array $input): ?int
    {
        if (!array_key_exists('parent_id', $input)) {
            return null;
        }
        $raw = $input['parent_id'];
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }
        if (!is_numeric($raw) || (int) $raw < 1) {
            throw ValidationException::withFields(['parent_id' => 'parent_id must be a page id, or null for the top level.']);
        }
        $parentId = (int) $raw;
        $this->requireLivePage($userId, $parentId);
        return $parentId;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireLivePage(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM pages WHERE id = ? AND user_id = ? AND archived_at IS NULL',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Page not found.');
        }
        $row['folder_id'] = $this->nullableInt($row['folder_id'] ?? null);
        $row['parent_id'] = $this->nullableInt($row['parent_id'] ?? null);
        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function childRows(int $userId, int $id): array
    {
        return array_map(
            fn (array $row): array => $this->brief($row),
            $this->db->fetchAll(
                'SELECT id, title, slug, kind, folder_id, parent_id, favorited_at, updated_at, updated_by, archived_at
                 FROM pages
                 WHERE user_id = ? AND parent_id = ? AND archived_at IS NULL
                 ORDER BY lower(title) ASC, id ASC',
                [$userId, $id]
            )
        );
    }

    /**
     * @return list<array{id: int, title: string}>
     */
    private function ancestors(int $userId, ?int $parentId): array
    {
        $out = [];
        $cursor = $parentId;
        $guard = 0;
        while ($cursor !== null && $guard < 8) {
            $row = $this->db->fetchOne(
                'SELECT id, title, parent_id FROM pages WHERE id = ? AND user_id = ? AND archived_at IS NULL',
                [$cursor, $userId]
            );
            if ($row === null) {
                break;
            }
            $out[] = ['id' => (int) $row['id'], 'title' => (string) $row['title']];
            $cursor = $this->nullableInt($row['parent_id']);
            $guard++;
        }
        return array_reverse($out);
    }

    /**
     * Other pages that name this title in prose, outside a wikilink.
     *
     * @return list<array{id: int, title: string, kind: string}>
     */
    private function mentions(int $userId, int $id, string $title): array
    {
        $title = trim($title);
        if (mb_strlen($title) < 3) {
            return [];
        }
        $needle = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($title)) . '%';
        $rows = $this->db->fetchAll(
            "SELECT id, title, kind, body FROM pages
             WHERE user_id = ? AND archived_at IS NULL AND id <> ?
               AND lower(body) LIKE ? ESCAPE '\\'
             ORDER BY lower(title) ASC, id ASC
             LIMIT 40",
            [$userId, $id, $needle]
        );
        $out = [];
        foreach ($rows as $row) {
            if (!Wikilinks::mentionsTitle((string) $row['body'], $title)) {
                continue;
            }
            $out[] = [
                'id'    => (int) $row['id'],
                'title' => (string) $row['title'],
                'kind'  => (string) $row['kind'],
            ];
            if (count($out) >= 12) {
                break;
            }
        }
        return $out;
    }

    private function renameIncoming(int $userId, int $pageId, string $from, string $to, string $actor, ?string $tool): void
    {
        $rows = $this->db->fetchAll(
            'SELECT p.id, p.title, p.body
             FROM pages p
             WHERE p.user_id = ? AND p.id <> ? AND p.archived_at IS NULL
               AND EXISTS (
                   SELECT 1 FROM page_links l
                   WHERE l.from_page_id = p.id AND l.user_id = p.user_id
                     AND (l.to_page_id = ? OR lower(l.to_title) = lower(?))
               )',
            [$userId, $pageId, $pageId, $from]
        );
        foreach ($rows as $row) {
            $next = Wikilinks::rewrite((string) $row['body'], $from, $to);
            if ($next === (string) $row['body']) {
                continue;
            }
            $id = (int) $row['id'];
            $version = (int) $this->db->fetchScalar(
                'SELECT COALESCE(MAX(version), 0) + 1 FROM page_revisions WHERE page_id = ?',
                [$id]
            );
            $this->db->execute(
                "UPDATE pages SET body = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
                [$next, $id, $userId]
            );
            $this->addRevision($userId, $id, $version, (string) $row['title'], $next);
            $this->syncLinks($userId, $id, $next);
            $this->audit->record($userId, $actor, $tool, 'page.update', 'page:' . $id, [
                'title'  => (string) $row['title'],
                'reason' => 'wikilink rename',
            ]);
        }
    }

    private function assertPageNesting(int $userId, int $id, int $parentId): void
    {
        $rows = $this->db->fetchAll(
            'SELECT id, parent_id FROM pages WHERE user_id = ? AND archived_at IS NULL',
            [$userId]
        );
        $parentOf = [];
        $children = [];
        foreach ($rows as $row) {
            $pageId = (int) $row['id'];
            $parent = $this->nullableInt($row['parent_id']);
            $parentOf[$pageId] = $parent;
            if ($parent !== null) {
                $children[$parent][] = $pageId;
            }
        }
        $depth = 0;
        $cursor = $parentId;
        $seen = [];
        while ($cursor !== null) {
            if ($cursor === $id || isset($seen[$cursor])) {
                throw ValidationException::withFields(['parent_id' => 'A page cannot be nested under its own subpage.']);
            }
            $seen[$cursor] = true;
            $depth++;
            if ($depth > 8) {
                throw ValidationException::withFields(['parent_id' => 'Pages can nest 8 levels deep.']);
            }
            $cursor = $parentOf[$cursor] ?? null;
        }
        if ($depth + $this->pageSubtreeHeight($id, $children) > 8) {
            throw ValidationException::withFields(['parent_id' => 'Pages can nest 8 levels deep.']);
        }
    }

    /**
     * @param array<int, list<int>> $children
     */
    private function pageSubtreeHeight(int $id, array $children): int
    {
        $height = 1;
        foreach ($children[$id] ?? [] as $child) {
            $height = max($height, 1 + $this->pageSubtreeHeight($child, $children));
        }
        return $height;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
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

    /**
     * @param array<string, mixed> $input
     * @return array{version: int|null, updated_at: string|null}|null
     */
    private function expectation(array $input): ?array
    {
        $hasVersion = array_key_exists('expected_version', $input) && $input['expected_version'] !== null && $input['expected_version'] !== '';
        $hasStamp = array_key_exists('expected_updated_at', $input) && $input['expected_updated_at'] !== null && $input['expected_updated_at'] !== '';
        if (!$hasVersion && !$hasStamp) {
            return null;
        }
        $version = null;
        if ($hasVersion) {
            $raw = $input['expected_version'];
            $digits = is_int($raw) || (is_string($raw) && preg_match('/^[1-9][0-9]*$/', $raw) === 1);
            if (!$digits) {
                throw ValidationException::withFields(['expected_version' => 'expected_version must be a positive integer.']);
            }
            $version = (int) $raw;
        }
        $stamp = null;
        if ($hasStamp) {
            $stamp = trim((string) $input['expected_updated_at']);
            if ($stamp === '' || strlen($stamp) > 40) {
                throw ValidationException::withFields(['expected_updated_at' => 'expected_updated_at must be the updated_at from the page you read.']);
            }
        }

        return ['version' => $version, 'updated_at' => $stamp];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockPage(int $userId, int $id): array
    {
        $lock = $this->db->driver() === 'pgsql' ? ' FOR UPDATE' : '';
        $row = $this->db->fetchOne(
            'SELECT * FROM pages WHERE id = ? AND user_id = ?' . $lock,
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Page not found.');
        }

        return $row;
    }

    /**
     * @param array{version: int|null, updated_at: string|null} $expected
     */
    private function assertFresh(int $userId, int $id, array $expected): void
    {
        $row = $this->db->fetchOne('SELECT updated_at FROM pages WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Page not found.');
        }
        $version = $this->currentVersion($id);
        $updated = (string) $row['updated_at'];
        $versionOk = $expected['version'] === null || $expected['version'] === $version;
        $stampOk = $expected['updated_at'] === null || $this->sameStamp($updated, $expected['updated_at']);
        if ($versionOk && $stampOk) {
            return;
        }
        throw new ConflictException(
            'This page changed since you read it. Current version is ' . $version . ' (' . $updated . '). Read it again. Nothing was written.',
            'stale_write',
            ['version' => $version, 'updated_at' => $updated]
        );
    }

    private function currentVersion(int $id): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COALESCE(MAX(version), 0) FROM page_revisions WHERE page_id = ?',
            [$id]
        );
    }

    private function sameStamp(string $stored, string $expected): bool
    {
        $norm = static function (string $value): string {
            $value = trim($value);
            $value = str_replace('T', ' ', $value);
            $value = rtrim($value, 'Zz');
            $value = preg_replace('/\.\d+/', '', $value) ?? $value;

            return substr($value, 0, 19);
        };
        $left = $norm($stored);
        $right = $norm($expected);

        return $left !== '' && $left === $right;
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
        $target = OllamaSettings::effective((new OllamaSettings($this->db))->get(), null);
        $prompt = mb_substr($title . "\n" . $body, 0, 8000);
        $vector = (new OllamaClient())->embeddings($target['base_url'], $target['embed_model'], $prompt, 20);
        if (is_array($vector) && $vector !== []) {
            $this->setEmbedding($userId, $pageId, $vector, $target['embed_model']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function semantic(int $userId, string $query, int $limit, bool $includeArchived = false): array
    {
        $target = OllamaSettings::effective((new OllamaSettings($this->db))->get(), null);
        $queryVec = (new OllamaClient())->embeddings(
            $target['base_url'],
            $target['embed_model'],
            mb_substr($query, 0, 2000),
            20
        );
        if (!is_array($queryVec) || $queryVec === []) {
            return [];
        }
        $live = $includeArchived ? '' : ' AND p.archived_at IS NULL';
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.title, p.slug, p.kind, p.folder_id, p.parent_id, p.favorited_at, p.updated_at, p.updated_by, p.archived_at, e.embedding
             FROM page_embeddings e JOIN pages p ON p.id = e.page_id
             WHERE e.user_id = ?{$live}",
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
            $row['score'] = $score;
            $scored[] = $row;
        }
        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function aliasHits(int $userId, string $query, ?string $like): array
    {
        $flag = $like === null ? '0' : '1';
        $pattern = $like ?? '%';
        return $this->db->fetchAll(
            "SELECT p.id, p.title, p.slug, p.kind, p.folder_id, p.parent_id, p.favorited_at,
                    p.updated_at, p.updated_by, p.archived_at, p.body, t.title AS former_title
             FROM page_titles t
             JOIN pages p ON p.id = t.page_id AND p.user_id = t.user_id
             WHERE t.user_id = ? AND (lower(t.title) = lower(?) OR (? = '1' AND t.title ILIKE ?))
             ORDER BY t.created_at DESC, t.id DESC
             LIMIT 200",
            [$userId, $query, $flag, $pattern]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $folderParams
     */
    private function rowVisible(array $row, bool $includeArchived, bool $folderSet, ?int $folderId, array $folderParams, ?string $since): bool
    {
        if (!$includeArchived && isset($row['archived_at']) && $row['archived_at'] !== null && $row['archived_at'] !== '') {
            return false;
        }
        if ($since !== null && (string) $row['updated_at'] < $since) {
            return false;
        }
        if (!$folderSet) {
            return true;
        }
        $id = isset($row['folder_id']) && $row['folder_id'] !== null && $row['folder_id'] !== '' ? (int) $row['folder_id'] : null;
        if ($folderId === null) {
            return $id === null;
        }
        return $id !== null && in_array($id, $folderParams, true);
    }

    private function editorLabel(?string $editor, string $actor): string
    {
        $label = trim((string) $editor);
        if ($label === '') {
            $label = $actor === 'ai' ? 'Agent' : ($actor !== '' ? $actor : 'user');
        }
        return mb_substr($label, 0, 80);
    }

    private function rememberTitle(int $userId, int $pageId, string $title, ?string $slug): void
    {
        $this->db->execute(
            'INSERT INTO page_titles (user_id, page_id, title, slug) VALUES (?, ?, ?, ?)',
            [$userId, $pageId, $title, $slug]
        );
    }

    /**
     * @return list<string>
     */
    private function formerTitles(int $userId, int $pageId): array
    {
        $out = [];
        foreach ($this->db->fetchAll(
            'SELECT title FROM page_titles WHERE user_id = ? AND page_id = ? ORDER BY created_at DESC, id DESC LIMIT 20',
            [$userId, $pageId]
        ) as $row) {
            $title = (string) $row['title'];
            $key = mb_strtolower($title);
            if (!isset($out[$key])) {
                $out[$key] = $title;
            }
        }
        return array_values($out);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalPageId(array $input): ?int
    {
        if (!array_key_exists('page_id', $input) || $input['page_id'] === null || $input['page_id'] === '') {
            return null;
        }
        if (!is_numeric($input['page_id']) || (int) $input['page_id'] < 1) {
            throw ValidationException::withFields(['page_id' => 'page_id must be a page id.']);
        }
        return (int) $input['page_id'];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalString(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        $value = trim((string) $input[$key]);
        return $value === '' ? null : $value;
    }

    /**
     * @return list<array{id: int, name: string, parent_id: ?int, path: string}>
     */
    private function folderCatalog(int $userId): array
    {
        $byId = [];
        foreach ($this->db->fetchAll(
            'SELECT id, parent_id, name FROM page_folders WHERE user_id = ? ORDER BY id ASC',
            [$userId]
        ) as $row) {
            $id = (int) $row['id'];
            $byId[$id] = [
                'id'        => $id,
                'name'      => (string) $row['name'],
                'parent_id' => $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : null,
            ];
        }
        $pathOf = function (int $id) use (&$pathOf, $byId): string {
            $folder = $byId[$id];
            if ($folder['parent_id'] === null || !isset($byId[$folder['parent_id']])) {
                return $folder['name'];
            }
            return $pathOf($folder['parent_id']) . '/' . $folder['name'];
        };
        $out = [];
        foreach ($byId as $id => $folder) {
            $folder['path'] = $pathOf($id);
            $out[] = $folder;
        }
        return $out;
    }

    /**
     * @param list<string> $paths
     */
    private function folderPathMessage(string $label, array $paths, bool $ambiguous): string
    {
        $shown = array_slice($paths, 0, 8);
        $list = implode(', ', array_map(static fn (string $path): string => '"' . $path . '"', $shown));
        if ($ambiguous) {
            return 'Folder "' . $label . '" matches more than one folder. Pass a full path: ' . $list . '.';
        }
        if ($shown === []) {
            return 'Folder not found. Pass a full path such as "Parent/' . $label . '".';
        }
        return 'Folder not found. Pass a full path such as ' . $list . '.';
    }

    /**
     * @param list<array{id: int, name: string, parent_id: ?int, path: string}> $catalog
     * @return list<string>
     */
    private function suggestPaths(array $catalog, string $leaf): array
    {
        $exact = [];
        $partial = [];
        foreach ($catalog as $folder) {
            if (strcasecmp($folder['name'], $leaf) === 0) {
                $exact[] = $folder['path'];
            } elseif ($leaf !== '' && (mb_stripos($folder['name'], $leaf) !== false || mb_stripos($leaf, $folder['name']) !== false)) {
                $partial[] = $folder['path'];
            }
        }
        if ($exact !== []) {
            return $exact;
        }
        if ($partial !== []) {
            return $partial;
        }
        return array_slice(array_column($catalog, 'path'), 0, 8);
    }

    /**
     * @return array{id: int, name: string, path: string}|null
     */
    private function suggestedFolder(int $userId, string $title): ?array
    {
        $folders = [];
        foreach ($this->folderCatalog($userId) as $folder) {
            $folders[] = [
                'id'   => $folder['id'],
                'name' => $folder['name'],
                'path' => $folder['path'],
            ];
        }
        return FolderSuggest::suggest($title, $folders);
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
     * @param array<string, mixed> $input
     */
    private function inputHasFolder(array $input): bool
    {
        return array_key_exists('folder_id', $input) || array_key_exists('folder', $input);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function folderFromInput(int $userId, array $input): ?int
    {
        if (array_key_exists('folder_id', $input)) {
            $raw = $input['folder_id'];
            if ($raw === null || $raw === '' || $raw === 0 || $raw === '0' || $raw === 'root') {
                return null;
            }
            if (!is_numeric($raw) || (int) $raw < 1) {
                throw ValidationException::withFields(['folder_id' => 'folder_id must be a folder on this vault.']);
            }
            $this->requireFolder($userId, (int) $raw);
            return (int) $raw;
        }
        $path = trim((string) ($input['folder'] ?? ''));
        if ($path === '' || strcasecmp($path, 'root') === 0) {
            return null;
        }
        return $this->folderIdByPath($userId, $path);
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function folderClause(int $userId, ?int $folderId, bool $folderSet, string $column): array
    {
        if (!$folderSet) {
            return ['', []];
        }
        if ($folderId === null) {
            return [' AND ' . $column . ' IS NULL', []];
        }
        $ids = $this->descendantFolderIds($userId, $folderId);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        return [' AND ' . $column . ' IN (' . $marks . ')', $ids];
    }

    /**
     * @return list<int>
     */
    private function descendantFolderIds(int $userId, int $folderId): array
    {
        $this->requireFolder($userId, $folderId);
        $rows = $this->db->fetchAll('SELECT id, parent_id FROM page_folders WHERE user_id = ?', [$userId]);
        $children = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : 0;
            $children[$parent][] = (int) $row['id'];
        }
        $out = [];
        $stack = [$folderId];
        while ($stack !== []) {
            $id = array_pop($stack);
            $out[] = $id;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        return $out;
    }

    /**
     * @return array{id: int, name: string, parent_id: ?int}
     */
    private function requireFolder(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT id, parent_id, name FROM page_folders WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Folder not found.');
        }
        return $this->formatFolder($row);
    }

    /**
     * @return array{id: int, name: string, parent_id: ?int}
     */
    private function folderRow(int $userId, int $id): array
    {
        return $this->requireFolder($userId, $id);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, name: string, parent_id: ?int}
     */
    private function formatFolder(array $row): array
    {
        return [
            'id'        => (int) $row['id'],
            'name'      => (string) $row['name'],
            'parent_id' => $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : null,
        ];
    }

    /**
     * @return list<array{id: int, name: string}>
     */
    private function breadcrumbs(int $userId, ?int $folderId): array
    {
        $out = [];
        $cursor = $folderId;
        $guard = 0;
        while ($cursor !== null && $guard < 16) {
            $row = $this->db->fetchOne(
                'SELECT id, parent_id, name FROM page_folders WHERE id = ? AND user_id = ?',
                [$cursor, $userId]
            );
            if ($row === null) {
                break;
            }
            array_unshift($out, ['id' => (int) $row['id'], 'name' => (string) $row['name']]);
            $cursor = $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : null;
            $guard++;
        }
        return $out;
    }

    private function assertNotDescendant(int $userId, int $id, ?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        if (in_array($parentId, $this->descendantFolderIds($userId, $id), true)) {
            throw ValidationException::withFields(['parent_id' => 'A folder cannot move inside its own child.']);
        }
    }

    private function subtreeHeight(int $userId, int $id): int
    {
        $rows = $this->db->fetchAll('SELECT id, parent_id FROM page_folders WHERE user_id = ?', [$userId]);
        $children = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null && $row['parent_id'] !== '' ? (int) $row['parent_id'] : 0;
            $children[$parent][] = (int) $row['id'];
        }
        $height = function (int $node) use (&$height, $children): int {
            $max = 0;
            foreach ($children[$node] ?? [] as $child) {
                $max = max($max, $height($child));
            }
            return 1 + $max;
        };
        return $height($id);
    }

    private function assertDepth(int $userId, int $parentId, int $adding = 1): void
    {
        $depth = $adding;
        $cursor = $parentId;
        while ($cursor !== null) {
            $depth++;
            if ($depth > 8) {
                throw ValidationException::withFields(['parent_id' => 'Folders can nest 8 levels deep.']);
            }
            $row = $this->requireFolder($userId, $cursor);
            $cursor = $row['parent_id'];
        }
    }

    private function cleanFolderName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 80 || str_contains($name, '/') || str_contains($name, '\\')) {
            throw ValidationException::withFields(['name' => 'Folder name must be 1–80 characters and cannot contain a slash.']);
        }
        return $name;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function brief(array $row): array
    {
        $id = (int) $row['id'];
        return [
            'id'          => $id,
            'title'       => (string) $row['title'],
            'slug'        => (string) $row['slug'],
            'kind'        => (string) $row['kind'],
            'folder_id'   => isset($row['folder_id']) && $row['folder_id'] !== null && $row['folder_id'] !== '' ? (int) $row['folder_id'] : null,
            'parent_id'   => $this->nullableInt($row['parent_id'] ?? null),
            'favorite'    => isset($row['favorited_at']) && $row['favorited_at'] !== null && $row['favorited_at'] !== '',
            'updated_at'  => (string) $row['updated_at'],
            'last_editor' => isset($row['updated_by']) ? (string) $row['updated_by'] : '',
            'archived'    => isset($row['archived_at']) && $row['archived_at'] !== null && $row['archived_at'] !== '',
            'url'         => PageUrl::forId($id),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function full(array $row): array
    {
        return $this->brief($row) + [
            'body'           => (string) $row['body'],
            'last_opened_at' => isset($row['last_opened_at']) && $row['last_opened_at'] !== null && $row['last_opened_at'] !== ''
                ? (string) $row['last_opened_at']
                : null,
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
