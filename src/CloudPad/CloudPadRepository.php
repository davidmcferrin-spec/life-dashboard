<?php

declare(strict_types=1);

namespace Dashboard\CloudPad;

use Dashboard\DB\Database;

final class CloudPadRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_cloudpad_canvases WHERE user_id = ? ORDER BY updated_at DESC, name ASC',
            [$userId],
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_cloudpad_canvases WHERE user_id = ?',
            [$userId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_cloudpad_canvases WHERE id = ?',
            [$id],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_cloudpad_canvases WHERE id = ? AND user_id = ?',
            [$id, $userId],
        );
    }

    public function slugExists(int $userId, string $slug, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT id FROM user_cloudpad_canvases WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return $this->db->fetchOne($sql, $params) !== null;
    }

    public function insert(int $userId, string $name, string $slug, string $content): int
    {
        $this->db->execute(
            'INSERT INTO user_cloudpad_canvases (user_id, name, slug, content, content_version)
             VALUES (?, ?, ?, ?, 1)',
            [$userId, $name, $slug, $content],
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateContent(int $id, string $content, int $expectedVersion): ?array
    {
        $affected = $this->db->execute(
            'UPDATE user_cloudpad_canvases
             SET content = ?, content_version = content_version + 1, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND content_version = ?',
            [$content, $id, $expectedVersion],
        );

        return $affected > 0 ? $this->findById($id) : null;
    }

    public function updateName(int $id, int $userId, string $name, string $slug): bool
    {
        return $this->db->execute(
            'UPDATE user_cloudpad_canvases SET name = ?, slug = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ?',
            [$name, $slug, $id, $userId],
        ) > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_cloudpad_canvases WHERE id = ? AND user_id = ?',
            [$id, $userId],
        ) > 0;
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateControlFields(int $id, array $fields): void
    {
        $sets   = ['updated_at = CURRENT_TIMESTAMP'];
        $params = [];

        foreach ([
            'control_user_id',
            'control_granted_at',
            'control_expires_at',
            'control_requested_by',
            'control_requested_at',
        ] as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[]   = "{$key} = ?";
            $params[] = $fields[$key];
        }

        if (count($sets) === 1) {
            return;
        }

        $params[] = $id;

        $this->db->execute(
            'UPDATE user_cloudpad_canvases SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params,
        );
    }

    public function clearExpiredControl(int $id, string $utcNow): void
    {
        $this->db->execute(
            'UPDATE user_cloudpad_canvases
             SET control_user_id = NULL,
                 control_granted_at = NULL,
                 control_expires_at = NULL,
                 control_requested_by = NULL,
                 control_requested_at = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?
               AND control_expires_at IS NOT NULL
               AND control_expires_at < ?',
            [$id, $utcNow],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);

        return $this->db->fetchOne(
            'SELECT id, username, email FROM users WHERE username = ? OR email = ?',
            [$identifier, $identifier],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserById(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, username, email FROM users WHERE id = ?',
            [$userId],
        );
    }
}
