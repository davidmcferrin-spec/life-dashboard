<?php

declare(strict_types=1);

namespace Dashboard\Graph;

use Dashboard\Audit\AuditLog;
use Dashboard\DB\Database;
use Dashboard\Knowledge\PageService;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Postgres adjacency over vault pages, bills, envelopes, digests, health days, and briefs.
 * Edges are wikilinks, brief or digest mentions, name matches, and manual links.
 */
final class GraphService
{
    public const KINDS = ['page', 'bill', 'envelope', 'digest', 'health', 'brief'];

    public const EDGE_KINDS = ['wikilink', 'mention', 'related', 'manual'];

    public function __construct(private readonly Database $db) {}

    public function sync(int $userId): array
    {
        (new PageService($this->db, new AuditLog($this->db)))->ensureProseLinks($userId);
        $this->db->transaction(function () use ($userId): void {
            $this->upsertSources($userId);
            $this->dropStale($userId);
            $this->db->execute(
                "DELETE FROM graph_edges WHERE user_id = ? AND kind IN ('wikilink', 'mention', 'related')",
                [$userId]
            );
            $ids = $this->ids($userId);
            $this->wikilinks($userId, $ids);
            $this->mentions($userId, $ids);
            $this->related($userId, $ids);
        });
        $nodes = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM graph_nodes WHERE user_id = ?', [$userId]);
        $edges = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM graph_edges WHERE user_id = ?', [$userId]);

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param list<string> $hidden
     * @return array<string, mixed>
     */
    public function query(int $userId, string $q, int $limit, array $hidden): array
    {
        $this->sync($userId);
        $limit = max(1, min(50, $limit));
        $q = trim($q);
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $rows = $this->db->fetchAll(
            'SELECT id, kind, ref_id, label FROM graph_nodes
             WHERE user_id = ? AND (? = \'\' OR label ILIKE ? ESCAPE \'\\\')
             ORDER BY kind, label
             LIMIT ' . $limit,
            [$userId, $q, $like]
        );
        $nodes = $this->visible($rows, $hidden);
        $ids = array_map(static fn (array $node): int => (int) $node['id'], $nodes);
        $edges = $ids === [] ? [] : $this->edgesAmong($userId, $ids);

        return [
            'nodes'   => $nodes,
            'edges'   => $edges,
            'omitted' => array_values($hidden),
        ];
    }

    /**
     * @param list<string> $hidden
     * @return array<string, mixed>
     */
    public function neighbors(int $userId, string $kind, int $refId, array $hidden): array
    {
        $this->assertKind($kind);
        $this->sync($userId);
        $node = $this->db->fetchOne(
            'SELECT id, kind, ref_id, label FROM graph_nodes WHERE user_id = ? AND kind = ? AND ref_id = ?',
            [$userId, $kind, $refId]
        );
        if ($node === null) {
            throw new NotFoundException('That node was not found.');
        }
        $id = (int) $node['id'];
        $rows = $this->db->fetchAll(
            'SELECT e.id, e.kind, e.from_id, e.to_id,
                    nf.kind AS from_kind, nf.ref_id AS from_ref, nf.label AS from_label,
                    nt.kind AS to_kind, nt.ref_id AS to_ref, nt.label AS to_label
             FROM graph_edges e
             JOIN graph_nodes nf ON nf.id = e.from_id
             JOIN graph_nodes nt ON nt.id = e.to_id
             WHERE e.user_id = ? AND (e.from_id = ? OR e.to_id = ?)',
            [$userId, $id, $id]
        );
        $edges = [];
        $nodes = [$this->node($node)];
        $seen = [$id => true];
        foreach ($rows as $row) {
            if (in_array((string) $row['from_kind'], $hidden, true) || in_array((string) $row['to_kind'], $hidden, true)) {
                continue;
            }
            $edges[] = $this->edge($row);
            foreach (['from', 'to'] as $side) {
                $nid = (int) $row[$side . '_id'];
                if (isset($seen[$nid])) {
                    continue;
                }
                $seen[$nid] = true;
                $nodes[] = [
                    'id'     => $nid,
                    'kind'   => (string) $row[$side . '_kind'],
                    'ref_id' => (int) $row[$side . '_ref'],
                    'label'  => (string) $row[$side . '_label'],
                ];
            }
        }

        return ['node' => $this->node($node), 'nodes' => $nodes, 'edges' => $edges, 'omitted' => array_values($hidden)];
    }

    /**
     * @return array<string, mixed>
     */
    public function link(int $userId, string $fromKind, int $fromRef, string $toKind, int $toRef): array
    {
        $this->assertKind($fromKind);
        $this->assertKind($toKind);
        if ($fromKind === $toKind && $fromRef === $toRef) {
            throw ValidationException::withFields(['to_ref' => 'A node cannot link to itself.']);
        }
        $this->sync($userId);
        $from = $this->requireNode($userId, $fromKind, $fromRef);
        $to = $this->requireNode($userId, $toKind, $toRef);
        $this->db->execute(
            'INSERT INTO graph_edges (user_id, from_id, to_id, kind) VALUES (?, ?, ?, \'manual\')
             ON CONFLICT (user_id, from_id, to_id, kind) DO NOTHING',
            [$userId, $from, $to]
        );

        return ['edge' => ['from_kind' => $fromKind, 'from_ref' => $fromRef, 'to_kind' => $toKind, 'to_ref' => $toRef, 'kind' => 'manual']];
    }

    private function upsertSources(int $userId): void
    {
        $this->db->execute(
            'INSERT INTO graph_nodes (user_id, kind, ref_id, label)
             SELECT user_id, \'page\', id, LEFT(title, 200) FROM pages WHERE user_id = ? AND archived_at IS NULL
             ON CONFLICT (user_id, kind, ref_id) DO UPDATE SET label = EXCLUDED.label',
            [$userId]
        );
        $this->db->execute(
            'INSERT INTO graph_nodes (user_id, kind, ref_id, label)
             SELECT user_id, \'bill\', id, LEFT(name, 200) FROM money_bills WHERE user_id = ? AND archived_at IS NULL
             ON CONFLICT (user_id, kind, ref_id) DO UPDATE SET label = EXCLUDED.label',
            [$userId]
        );
        $this->db->execute(
            'INSERT INTO graph_nodes (user_id, kind, ref_id, label)
             SELECT user_id, \'envelope\', id, LEFT(name, 200) FROM money_envelopes WHERE user_id = ? AND archived_at IS NULL
             ON CONFLICT (user_id, kind, ref_id) DO UPDATE SET label = EXCLUDED.label',
            [$userId]
        );
        $this->db->execute(
            'INSERT INTO graph_nodes (user_id, kind, ref_id, label)
             SELECT user_id, \'digest\', id, LEFT(title, 200) FROM (
                SELECT id, user_id, title FROM digest_entries WHERE user_id = ? ORDER BY id DESC LIMIT 80
             ) recent
             ON CONFLICT (user_id, kind, ref_id) DO UPDATE SET label = EXCLUDED.label',
            [$userId]
        );
        $this->db->execute(
            'INSERT INTO graph_nodes (user_id, kind, ref_id, label)
             SELECT user_id, \'health\', id, \'Health \' || to_char(day, \'YYYY-MM-DD\') FROM health_daily WHERE user_id = ?
             ON CONFLICT (user_id, kind, ref_id) DO UPDATE SET label = EXCLUDED.label',
            [$userId]
        );
        $this->db->execute(
            'INSERT INTO graph_nodes (user_id, kind, ref_id, label)
             SELECT user_id, \'brief\', id, \'Brief \' || to_char(composed_at, \'YYYY-MM-DD HH24:MI\')
             FROM morning_briefs WHERE user_id = ?
             ON CONFLICT (user_id, kind, ref_id) DO UPDATE SET label = EXCLUDED.label',
            [$userId]
        );
    }

    private function dropStale(int $userId): void
    {
        $this->db->execute(
            'DELETE FROM graph_nodes n WHERE n.user_id = ? AND n.kind = \'page\'
             AND NOT EXISTS (SELECT 1 FROM pages p WHERE p.id = n.ref_id AND p.user_id = n.user_id AND p.archived_at IS NULL)',
            [$userId]
        );
        $this->db->execute(
            'DELETE FROM graph_nodes n WHERE n.user_id = ? AND n.kind = \'bill\'
             AND NOT EXISTS (SELECT 1 FROM money_bills b WHERE b.id = n.ref_id AND b.user_id = n.user_id AND b.archived_at IS NULL)',
            [$userId]
        );
        $this->db->execute(
            'DELETE FROM graph_nodes n WHERE n.user_id = ? AND n.kind = \'envelope\'
             AND NOT EXISTS (SELECT 1 FROM money_envelopes e WHERE e.id = n.ref_id AND e.user_id = n.user_id AND e.archived_at IS NULL)',
            [$userId]
        );
        $this->db->execute(
            'DELETE FROM graph_nodes n WHERE n.user_id = ? AND n.kind = \'digest\'
             AND NOT EXISTS (
                SELECT 1 FROM (
                    SELECT id FROM digest_entries WHERE user_id = ? ORDER BY id DESC LIMIT 80
                ) recent WHERE recent.id = n.ref_id
             )',
            [$userId, $userId]
        );
        $this->db->execute(
            'DELETE FROM graph_nodes n WHERE n.user_id = ? AND n.kind = \'health\'
             AND NOT EXISTS (SELECT 1 FROM health_daily h WHERE h.id = n.ref_id AND h.user_id = n.user_id)',
            [$userId]
        );
        $this->db->execute(
            'DELETE FROM graph_nodes n WHERE n.user_id = ? AND n.kind = \'brief\'
             AND NOT EXISTS (SELECT 1 FROM morning_briefs b WHERE b.id = n.ref_id AND b.user_id = n.user_id)',
            [$userId]
        );
    }

    /**
     * @return array<string, int>
     */
    private function ids(int $userId): array
    {
        $rows = $this->db->fetchAll('SELECT id, kind, ref_id, label FROM graph_nodes WHERE user_id = ?', [$userId]);
        $ids = [];
        foreach ($rows as $row) {
            $ids[(string) $row['kind'] . ':' . $row['ref_id']] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * @param array<string, int> $ids
     */
    private function wikilinks(int $userId, array $ids): void
    {
        $rows = $this->db->fetchAll(
            'SELECT from_page_id, to_page_id FROM page_links WHERE user_id = ? AND to_page_id IS NOT NULL',
            [$userId]
        );
        foreach ($rows as $row) {
            $from = $ids['page:' . $row['from_page_id']] ?? null;
            $to = $ids['page:' . $row['to_page_id']] ?? null;
            if ($from !== null && $to !== null && $from !== $to) {
                $this->edgeInsert($userId, $from, $to, 'wikilink');
            }
        }
    }

    /**
     * @param array<string, int> $ids
     */
    private function mentions(int $userId, array $ids): void
    {
        $labels = $this->labels($userId, ['page', 'bill', 'envelope']);
        $texts = array_merge(
            $this->texts('SELECT id, body AS text FROM morning_briefs WHERE user_id = ? ORDER BY id DESC LIMIT 14', $userId, 'brief'),
            $this->texts(
                'SELECT id, COALESCE(brief, summary, title) AS text FROM digest_entries WHERE user_id = ? ORDER BY id DESC LIMIT 80',
                $userId,
                'digest'
            )
        );
        foreach ($texts as $text) {
            $from = $ids[$text['kind'] . ':' . $text['id']] ?? null;
            if ($from === null) {
                continue;
            }
            foreach ($labels as $label) {
                if ($label['kind'] === $text['kind'] && $label['ref'] === $text['id']) {
                    continue;
                }
                if (!self::contains($text['text'], $label['label'])) {
                    continue;
                }
                $to = $ids[$label['kind'] . ':' . $label['ref']] ?? null;
                if ($to !== null && $to !== $from) {
                    $this->edgeInsert($userId, $from, $to, 'mention');
                }
            }
        }
    }

    /**
     * @param array<string, int> $ids
     */
    private function related(int $userId, array $ids): void
    {
        $labels = $this->labels($userId, ['bill', 'envelope']);
        $pages = $this->db->fetchAll(
            'SELECT id, body FROM pages WHERE user_id = ? AND archived_at IS NULL ORDER BY updated_at DESC LIMIT 200',
            [$userId]
        );
        foreach ($pages as $page) {
            $from = $ids['page:' . $page['id']] ?? null;
            if ($from === null) {
                continue;
            }
            $body = (string) $page['body'];
            foreach ($labels as $label) {
                if (!self::contains($body, $label['label'])) {
                    continue;
                }
                $to = $ids[$label['kind'] . ':' . $label['ref']] ?? null;
                if ($to !== null) {
                    $this->edgeInsert($userId, $from, $to, 'related');
                }
            }
        }
    }

    /**
     * @param list<string> $kinds
     * @return list<array{kind: string, ref: int, label: string}>
     */
    private function labels(int $userId, array $kinds): array
    {
        $rows = $this->db->fetchAll(
            'SELECT kind, ref_id, label FROM graph_nodes WHERE user_id = ? AND kind IN (\'page\', \'bill\', \'envelope\', \'digest\', \'health\', \'brief\')',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            if (!in_array((string) $row['kind'], $kinds, true)) {
                continue;
            }
            $label = trim((string) $row['label']);
            if (mb_strlen($label) < 3) {
                continue;
            }
            $out[] = ['kind' => (string) $row['kind'], 'ref' => (int) $row['ref_id'], 'label' => $label];
        }

        return $out;
    }

    /**
     * @return list<array{kind: string, id: int, text: string}>
     */
    private function texts(string $sql, int $userId, string $kind): array
    {
        $rows = $this->db->fetchAll($sql, [$userId]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['kind' => $kind, 'id' => (int) $row['id'], 'text' => (string) $row['text']];
        }

        return $out;
    }

    public static function contains(string $haystack, string $needle): bool
    {
        if ($needle === '' || mb_strlen($needle) < 3) {
            return false;
        }
        $pattern = '/(?<![[:alnum:]])' . preg_quote($needle, '/') . '(?![[:alnum:]])/iu';

        return preg_match($pattern, $haystack) === 1;
    }

    private function edgeInsert(int $userId, int $from, int $to, string $kind): void
    {
        $this->db->execute(
            'INSERT INTO graph_edges (user_id, from_id, to_id, kind) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, from_id, to_id, kind) DO NOTHING',
            [$userId, $from, $to, $kind]
        );
    }

    /**
     * @param list<int> $ids
     * @return list<array<string, mixed>>
     */
    private function edgesAmong(int $userId, array $ids): array
    {
        $rows = $this->db->fetchAll(
            'SELECT e.id, e.kind, e.from_id, e.to_id,
                    nf.kind AS from_kind, nf.ref_id AS from_ref, nf.label AS from_label,
                    nt.kind AS to_kind, nt.ref_id AS to_ref, nt.label AS to_label
             FROM graph_edges e
             JOIN graph_nodes nf ON nf.id = e.from_id
             JOIN graph_nodes nt ON nt.id = e.to_id
             WHERE e.user_id = ?
             ORDER BY e.id DESC
             LIMIT 200',
            [$userId]
        );
        $want = array_fill_keys($ids, true);
        $out = [];
        foreach ($rows as $row) {
            if (!isset($want[(int) $row['from_id']]) && !isset($want[(int) $row['to_id']])) {
                continue;
            }
            $out[] = $this->edge($row);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $hidden
     * @return list<array<string, mixed>>
     */
    private function visible(array $rows, array $hidden): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (in_array((string) $row['kind'], $hidden, true)) {
                continue;
            }
            $out[] = $this->node($row);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function node(array $row): array
    {
        return [
            'id'     => (int) $row['id'],
            'kind'   => (string) $row['kind'],
            'ref_id' => (int) $row['ref_id'],
            'label'  => (string) $row['label'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function edge(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'kind'       => (string) $row['kind'],
            'from_id'    => (int) $row['from_id'],
            'to_id'      => (int) $row['to_id'],
            'from_kind'  => (string) $row['from_kind'],
            'from_ref'   => (int) $row['from_ref'],
            'from_label' => (string) $row['from_label'],
            'to_kind'    => (string) $row['to_kind'],
            'to_ref'     => (int) $row['to_ref'],
            'to_label'   => (string) $row['to_label'],
        ];
    }

    private function requireNode(int $userId, string $kind, int $ref): int
    {
        $row = $this->db->fetchOne(
            'SELECT id FROM graph_nodes WHERE user_id = ? AND kind = ? AND ref_id = ?',
            [$userId, $kind, $ref]
        );
        if ($row === null) {
            throw new NotFoundException('That ' . $kind . ' is not in the graph.');
        }

        return (int) $row['id'];
    }

    private function assertKind(string $kind): void
    {
        if (!in_array($kind, self::KINDS, true)) {
            throw ValidationException::withFields(['kind' => 'Unknown node kind.']);
        }
    }
}
