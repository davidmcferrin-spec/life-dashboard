<?php

declare(strict_types=1);

namespace Dashboard\Package;

use Dashboard\DB\Database;
use Dashboard\Support\SqlTime;

final class PackageWatchRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, bool $activeOnly = true): array
    {
        $sql    = 'SELECT * FROM user_package_watches WHERE user_id = ?';
        $params = [$userId];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1 AND is_complete = 0';
        } else {
            $sql .= ' AND is_complete = 0';
        }

        $sql .= ' ORDER BY sort_order ASC, created_at ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDueForPoll(int $pollMinutes): array
    {
        $pollMinutes   = max(1, $pollMinutes);
        $checkedBefore = SqlTime::utcMinutesAgo($pollMinutes); // last_checked_at is UTC (CURRENT_TIMESTAMP)

        $linkOnly = implode(',', array_map(
            static fn (string $c): string => "'" . $c . "'",
            CarrierDetector::LINK_ONLY_CARRIERS,
        ));

        return $this->db->fetchAll(
            "SELECT * FROM user_package_watches
             WHERE is_active = 1
               AND is_complete = 0
               AND carrier NOT IN ({$linkOnly})
               AND (
                    last_checked_at IS NULL
                    OR last_checked_at <= ?
               )
             ORDER BY sort_order ASC",
            [$checkedBefore]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_package_watches WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function countActiveForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_package_watches
             WHERE user_id = ? AND is_active = 1 AND is_complete = 0',
            [$userId]
        );
    }

    public function nextSortOrder(int $userId): int
    {
        $max = (int) $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM user_package_watches WHERE user_id = ?',
            [$userId]
        );

        return $max + 1;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            'INSERT INTO user_package_watches
                (user_id, tracking_number, carrier, label, external_url, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['user_id'],
                $data['tracking_number'],
                $data['carrier'],
                $data['label'] ?? null,
                $data['external_url'] ?? null,
                $data['is_active'] ?? 1,
                $data['sort_order'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function applyStatus(int $id, PackageStatusResult $result): void
    {
        $this->db->execute(
            'UPDATE user_package_watches SET
                status_label = ?,
                status_detail = ?,
                estimated_delivery_at = ?,
                last_event_at = ?,
                external_url = COALESCE(?, external_url),
                raw_json = ?,
                is_complete = CASE WHEN ? = 1 THEN 1 ELSE is_complete END,
                completed_at = CASE WHEN ? = 1 THEN COALESCE(completed_at, CURRENT_TIMESTAMP) ELSE completed_at END,
                last_checked_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [
                $result->statusLabel,
                $result->statusDetail,
                $result->estimatedDeliveryAt,
                $result->lastEventAt,
                $result->externalUrl,
                $result->raw !== null ? json_encode($result->raw) : null,
                $result->markComplete ? 1 : 0,
                $result->markComplete ? 1 : 0,
                $id,
            ]
        );
    }

    public function markComplete(int $id, int $userId): void
    {
        $this->db->execute(
            'UPDATE user_package_watches SET
                is_complete = 1,
                completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)
             WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function deleteExpiredBefore(string $cutoffDatetime): int
    {
        $this->db->execute(
            'UPDATE user_package_watches SET completed_at = COALESCE(last_checked_at, created_at)
             WHERE is_complete = 1 AND completed_at IS NULL'
        );

        return $this->db->execute(
            'DELETE FROM user_package_watches
             WHERE completed_at IS NOT NULL
               AND completed_at <= ?',
            [$cutoffDatetime]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): bool
    {
        $sets   = [];
        $params = [];

        foreach ([
            'tracking_number', 'carrier', 'label', 'external_url',
            'is_active', 'is_complete', 'sort_order',
        ] as $key) {
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
            'UPDATE user_package_watches SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_package_watches WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }
}
