<?php

declare(strict_types=1);

namespace Dashboard\Flight;

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Support\SqlTime;

final class FlightWatchRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM user_flight_watches WHERE user_id = ?';
        $params = [$userId];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1 AND is_complete = 0';
        } else {
            $sql .= ' AND is_complete = 0';
        }

        $sql .= ' ORDER BY departure_at ASC, sort_order ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listIncomplete(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_flight_watches WHERE is_complete = 0 ORDER BY departure_at ASC'
        );
    }

    public function markComplete(int $id, int $userId): void
    {
        $this->db->execute(
            'UPDATE user_flight_watches SET
                is_complete = 1,
                completed_at = COALESCE(completed_at, CURRENT_TIMESTAMP)
             WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function deleteExpiredBefore(string $cutoffDatetime): int
    {
        $this->db->execute(
            'UPDATE user_flight_watches SET completed_at = COALESCE(last_checked_at, departure_at)
             WHERE is_complete = 1 AND completed_at IS NULL'
        );

        return $this->db->execute(
            'DELETE FROM user_flight_watches
             WHERE completed_at IS NOT NULL
               AND completed_at <= ?',
            [$cutoffDatetime]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDueForPoll(int $pollMinutes): array
    {
        $pollMinutes = max(1, $pollMinutes);

        // departure_at is wall-clock APP_TIMEZONE (see FlightWatchService::isInPollWindow);
        // last_checked_at is written with CURRENT_TIMESTAMP (UTC).
        // The legacy SQLite query compared departure_at against UTC now, which opened the
        // poll window 5–6 h late in US Central — fixed here to match the service logic.
        $windowOpensBefore = SqlTime::localHoursFromNow(24, Config::get('APP_TIMEZONE', 'America/New_York'));
        $checkedBefore     = SqlTime::utcMinutesAgo($pollMinutes);

        return $this->db->fetchAll(
            'SELECT * FROM user_flight_watches
             WHERE is_active = 1
               AND is_complete = 0
               AND departure_at <= ?
               AND (
                    last_checked_at IS NULL
                    OR last_checked_at <= ?
               )
             ORDER BY departure_at ASC',
            [$windowOpensBefore, $checkedBefore]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_flight_watches WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_flight_watches WHERE user_id = ?',
            [$userId]
        );
    }

    public function nextSortOrder(int $userId): int
    {
        $max = (int) $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM user_flight_watches WHERE user_id = ?',
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
            'INSERT INTO user_flight_watches
                (user_id, ident, departure_at, arrival_at, origin_icao, dest_icao, is_active, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
            [
                $data['user_id'],
                $data['ident'],
                $data['departure_at'],
                $data['arrival_at'] ?? null,
                $data['origin_icao'] ?? null,
                $data['dest_icao'] ?? null,
                $data['sort_order'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function applyStatus(int $id, FlightStatusResult $result): void
    {
        $this->db->execute(
            'UPDATE user_flight_watches SET
                status_label = ?,
                airborne = ?,
                latitude = ?,
                longitude = ?,
                altitude_m = ?,
                velocity_ms = ?,
                heading = ?,
                origin_display = COALESCE(?, origin_display),
                dest_display = COALESCE(?, dest_display),
                icao24 = COALESCE(?, icao24),
                raw_json = ?,
                is_complete = CASE WHEN ? = 1 THEN 1 ELSE is_complete END,
                completed_at = CASE WHEN ? = 1 THEN COALESCE(completed_at, CURRENT_TIMESTAMP) ELSE completed_at END,
                last_checked_at = CURRENT_TIMESTAMP
             WHERE id = ?',
            [
                $result->statusLabel,
                $result->airborne ? 1 : 0,
                $result->latitude,
                $result->longitude,
                $result->altitudeM,
                $result->velocityMs,
                $result->heading,
                $result->originDisplay,
                $result->destDisplay,
                $result->icao24,
                $result->raw !== null ? json_encode($result->raw) : null,
                $result->markComplete ? 1 : 0,
                $result->markComplete ? 1 : 0,
                $id,
            ]
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
            'ident', 'departure_at', 'arrival_at', 'origin_icao', 'dest_icao',
            'is_active', 'is_complete', 'completed_at', 'sort_order',
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
            'UPDATE user_flight_watches SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_flight_watches WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }
}
