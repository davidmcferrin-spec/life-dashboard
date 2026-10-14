<?php

declare(strict_types=1);

namespace Dashboard\Audit;

use Dashboard\DB\Database;

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
        $resource = $resource !== null ? mb_substr($resource, 0, 160) : null;
        $json = json_encode($detail === [] ? new \stdClass() : $detail, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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
}
