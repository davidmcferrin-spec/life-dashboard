<?php

declare(strict_types=1);

namespace Dashboard\Ping;

use Dashboard\DB\Database;

final class PingRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM user_ping_targets WHERE user_id = ?';
        $params = [$userId];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY sort_order ASC, label ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllActive(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_ping_targets WHERE is_active = 1 ORDER BY user_id ASC, sort_order ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_ping_targets WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_ping_targets WHERE user_id = ?',
            [$userId]
        );
    }

    public function nextSortOrder(int $userId): int
    {
        $max = (int) $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM user_ping_targets WHERE user_id = ?',
            [$userId]
        );

        return $max + 1;
    }

    public function insert(int $userId, string $label, string $target, int $sortOrder): int
    {
        $this->db->execute(
            'INSERT INTO user_ping_targets (user_id, label, target, sort_order) VALUES (?, ?, ?, ?)',
            [$userId, $label, $target, $sortOrder]
        );

        return (int) $this->db->lastInsertId();
    }

    public function applyCheck(int $id, bool $up, ?int $latencyMs, string $message): void
    {
        $this->db->execute(
            'UPDATE user_ping_targets SET
                is_up = ?, latency_ms = ?, status_message = ?, last_checked_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$up ? 1 : 0, $latencyMs, mb_substr($message, 0, 255), $id]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): bool
    {
        $sets   = [];
        $params = [];

        foreach (['label', 'target', 'sort_order', 'is_active'] as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[]   = "{$key} = ?";
            $params[] = $fields[$key];
        }

        if ($sets === []) {
            return false;
        }

        $params[] = $id;
        $params[] = $userId;

        return $this->db->execute(
            'UPDATE user_ping_targets SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_ping_targets WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }
}
