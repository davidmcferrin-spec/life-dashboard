<?php

declare(strict_types=1);

namespace Dashboard\Audit;

use Dashboard\DB\Database;
use Dashboard\Money\AccountNumberGuard;
use Dashboard\Support\ValidationException;

/**
 * Append-only record of API and MCP writes. The brain view reads recent
 * page resources from here and lights those nodes.
 */
final class AuditLog
{
    public const ACTORS = ['user', 'ai', 'system', 'integration'];

    public function __construct(private readonly Database $db) {}

    /**
     * @param array<string, mixed> $detail
     */
    public function record(
        int $userId,
        string $actor,
        ?string $tool,
        string $action,
        ?string $resource,
        array $detail = [],
    ): void {
        if (!in_array($actor, self::ACTORS, true)) {
            $actor = 'user';
        }
        $tool = $tool !== null ? mb_substr(trim($tool), 0, 64) : null;
        $tool = $tool === '' ? null : $tool;
        $action = mb_substr(trim($action), 0, 64);
        $resource = $resource !== null ? mb_substr(AccountNumberGuard::redact($resource) ?? $resource, 0, 160) : null;
        $json = json_encode($detail === [] ? new \stdClass() : $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $json = AccountNumberGuard::redact($json) ?? $json;
        if (strlen($json) > 4000) {
            $json = '{}';
        }
        $this->db->execute(
            'INSERT INTO audit_events (user_id, actor, tool, action, resource, detail) VALUES (?, ?, ?, ?, ?, ?::jsonb)',
            [$userId, $actor, $tool, $action, $resource, $json]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $userId, int $limit = 40): array
    {
        $limit = max(1, min(200, $limit));
        $rows = $this->db->fetchAll(
            "SELECT id, actor, tool, action, resource, detail, created_at
             FROM audit_events WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT {$limit}",
            [$userId]
        );
        return array_map(static function (array $row): array {
            $detail = is_string($row['detail']) ? json_decode($row['detail'], true) : $row['detail'];
            return [
                'id'         => (int) $row['id'],
                'actor'      => (string) $row['actor'],
                'tool'       => $row['tool'] !== null ? (string) $row['tool'] : null,
                'action'     => (string) $row['action'],
                'resource'   => $row['resource'] !== null ? (string) $row['resource'] : null,
                'detail'     => is_array($detail) ? $detail : [],
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * Bearer and MCP calls for the presence sprite. Detail is reduced to a name and a label.
     *
     * @return list<array{id: int, agent: string, area: string, tool: string, label: string, at: string}>
     */
    public function presence(int $userId, int $afterId = 0, int $limit = 20): array
    {
        $limit = max(1, min(50, $limit));
        $afterId = max(0, $afterId);
        $rows = $this->db->fetchAll(
            "SELECT id, actor, tool, action, resource, detail, created_at
             FROM audit_events
             WHERE user_id = ? AND actor = 'ai' AND id > ?
             ORDER BY id ASC
             LIMIT {$limit}",
            [$userId, $afterId]
        );
        $out = [];
        foreach ($rows as $row) {
            $detail = is_string($row['detail']) ? json_decode($row['detail'], true) : $row['detail'];
            $row['detail'] = is_array($detail) ? $detail : [];
            $item = AgentPresence::describe($row);
            if ($item !== null) {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Page ids touched in the audit log during the last 7 days.
     *
     * @return list<int>
     */
    public function hotPageIds(int $userId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT DISTINCT resource FROM audit_events
             WHERE user_id = ? AND resource LIKE 'page:%'
               AND created_at >= ((now() AT TIME ZONE 'UTC') - INTERVAL '7 days')",
            [$userId]
        );
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) substr((string) $row['resource'], 5);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Filtered audit trail. Detail never includes notes, titles, payees, or account numbers.
     *
     * @param array<string, mixed> $filters
     * @param list<string>|null $entityAllow null means every entity
     * @return list<array<string, mixed>>
     */
    public function search(int $userId, array $filters, ?array $entityAllow = null): array
    {
        if ($entityAllow === []) {
            return [];
        }
        $where = ['user_id = ?'];
        $params = [$userId];

        $since = $this->bound($filters['since'] ?? null, false, 'since');
        if ($since !== null) {
            $where[] = 'created_at >= ?';
            $params[] = $since;
        }
        $until = $this->bound($filters['until'] ?? null, true, 'until');
        if ($until !== null) {
            $where[] = 'created_at <= ?';
            $params[] = $until;
        }

        $actor = isset($filters['actor']) ? trim((string) $filters['actor']) : '';
        if ($actor !== '') {
            if (!in_array($actor, self::ACTORS, true)) {
                throw ValidationException::withFields(['actor' => 'Actor must be ai, user, system, or integration.']);
            }
            $where[] = 'actor = ?';
            $params[] = $actor;
        }

        $tool = isset($filters['tool']) ? trim((string) $filters['tool']) : '';
        if ($tool !== '') {
            $where[] = 'tool = ?';
            $params[] = mb_substr($tool, 0, 64);
        }

        $entity = isset($filters['entity']) ? trim((string) $filters['entity']) : '';
        $entities = $entity !== '' ? [$entity] : ($entityAllow ?? []);
        if ($entities !== []) {
            $parts = [];
            foreach ($entities as $name) {
                $likes = self::ENTITY_PATHS[$name] ?? null;
                if ($likes === null) {
                    throw ValidationException::withFields(['entity' => 'Unknown entity type.']);
                }
                $ors = ["detail->>'entity' = ?"];
                $params[] = $name;
                foreach ($likes as $like) {
                    $ors[] = 'resource LIKE ?';
                    $params[] = $like;
                }
                $parts[] = '(' . implode(' OR ', $ors) . ')';
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }

        $entityId = $filters['entity_id'] ?? null;
        if ($entityId !== null && $entityId !== '') {
            if (!is_numeric($entityId) || (int) $entityId < 1) {
                throw ValidationException::withFields(['entity_id' => 'Entity id must be a positive number.']);
            }
            $id = (string) (int) $entityId;
            $idOrs = ["detail->>'entity_id' = ?"];
            $params[] = $id;
            $paths = $entity !== '' && isset(self::ENTITY_PATHS[$entity])
                ? self::ENTITY_PATHS[$entity]
                : array_merge(...array_values(self::ENTITY_PATHS));
            foreach ($paths as $like) {
                $base = rtrim($like, '%');
                $idOrs[] = 'resource LIKE ?';
                $params[] = $base . '/' . $id;
                $idOrs[] = 'resource LIKE ?';
                $params[] = $base . '/' . $id . '/%';
            }
            $where[] = '(' . implode(' OR ', $idOrs) . ')';
        }

        $limit = (int) ($filters['limit'] ?? 50);
        $limit = max(1, min(200, $limit === 0 ? 50 : $limit));
        $sql = 'SELECT id, actor, tool, action, resource, detail, created_at
                FROM audit_events WHERE ' . implode(' AND ', $where) . "
                ORDER BY created_at DESC, id DESC LIMIT {$limit}";
        $rows = $this->db->fetchAll($sql, $params);
        return array_map(fn (array $row): array => $this->formatEvent($row), $rows);
    }

    /** @var array<string, list<string>> */
    private const ENTITY_PATHS = [
        'debt'          => ['%/money/debts%'],
        'account'       => ['%/money/accounts%'],
        'transaction'   => ['%/money/transactions%'],
        'budget'        => ['%/money/budget%', '%/money/category-caps%'],
        'sinking_fund'  => ['%/money/sinking-funds%'],
        'todo'          => ['%/todos/items%'],
        'todo_category' => ['%/todos/categories%'],
    ];

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatEvent(array $row): array
    {
        $detail = is_string($row['detail']) ? json_decode($row['detail'], true) : $row['detail'];
        $resource = $row['resource'] !== null ? (string) $row['resource'] : null;
        return [
            'id'         => (int) $row['id'],
            'actor'      => (string) $row['actor'],
            'tool'       => $row['tool'] !== null ? (string) $row['tool'] : null,
            'action'     => (string) $row['action'],
            'resource'   => AccountNumberGuard::redact($resource),
            'detail'     => $this->sanitizeDetail(is_array($detail) ? $detail : []),
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $detail
     * @return array<string, mixed>
     */
    private function sanitizeDetail(array $detail): array
    {
        $drop = ['password', 'secret', 'token', 'account_number', 'pan', 'authorization', 'bearer', 'notes', 'title', 'payee', 'name', 'body'];
        $out = [];
        foreach ($detail as $key => $value) {
            $name = (string) $key;
            if (in_array(strtolower($name), $drop, true)) {
                continue;
            }
            if (is_array($value)) {
                $out[$name] = $this->sanitizeDetail($value);
                continue;
            }
            if (is_string($value)) {
                $out[$name] = AccountNumberGuard::redact($value);
                continue;
            }
            $out[$name] = $value;
        }
        return $out;
    }

    private function bound(mixed $value, bool $end, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text . ($end ? ' 23:59:59' : ' 00:00:00');
        }
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $text)
            ?: \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $text);
        if ($dt === false) {
            throw ValidationException::withFields([$field => 'Use a date (YYYY-MM-DD) or a timestamp.']);
        }
        return $dt->format('Y-m-d H:i:s');
    }
}
