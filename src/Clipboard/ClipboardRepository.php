<?php

declare(strict_types=1);

namespace Dashboard\Clipboard;

use Dashboard\DB\Database;

/**
 * Persistence for per-user cloud clipboards.
 */
final class ClipboardRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUser(int $userId, bool $pinnedOnly = false): array
    {
        $sql = 'SELECT * FROM user_clipboards WHERE user_id = ?';
        $params = [$userId];

        if ($pinnedOnly) {
            $sql .= ' AND pin_to_dashboard = 1';
        }

        $sql .= ' ORDER BY sort_order ASC, name ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_clipboards WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_clipboards WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBySlugForUser(string $slug, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_clipboards WHERE slug = ? AND user_id = ?',
            [$slug, $userId]
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_clipboards WHERE user_id = ?',
            [$userId]
        );
    }

    public function slugExists(int $userId, string $slug, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT id FROM user_clipboards WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return $this->db->fetchOne($sql, $params) !== null;
    }

    public function nextSortOrder(int $userId): int
    {
        $max = $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM user_clipboards WHERE user_id = ?',
            [$userId]
        );

        return (int) $max + 1;
    }

    /**
     * @param array{user_id: int, name: string, slug: string, content: string, pin_to_dashboard: int, auto_save: int, sort_order: int} $data
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            'INSERT INTO user_clipboards
                (user_id, name, slug, content, content_version, pin_to_dashboard, auto_save, sort_order)
             VALUES (?, ?, ?, ?, 1, ?, ?, ?)',
            [
                $data['user_id'],
                $data['name'],
                $data['slug'],
                $data['content'],
                $data['pin_to_dashboard'],
                $data['auto_save'],
                $data['sort_order'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null Updated row or null if not found / version mismatch
     */
    public function updateContent(int $id, int $userId, string $content, int $expectedVersion): ?array
    {
        $affected = $this->db->execute(
            'UPDATE user_clipboards
             SET content = ?,
                 content_version = content_version + 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ? AND content_version = ?',
            [$content, $id, $userId, $expectedVersion]
        );

        if ($affected === 0) {
            return null;
        }

        return $this->findByIdForUser($id, $userId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function updateContentById(int $id, string $content, int $expectedVersion): ?array
    {
        $affected = $this->db->execute(
            'UPDATE user_clipboards
             SET content = ?,
                 content_version = content_version + 1,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND content_version = ?',
            [$content, $id, $expectedVersion]
        );

        if ($affected === 0) {
            return null;
        }

        return $this->findById($id);
    }

    /**
     * @param array<string, mixed> $fields name, slug, pin_to_dashboard, auto_save, sort_order
     */
    public function updateSettings(int $id, int $userId, array $fields): bool
    {
        $sets   = ['updated_at = CURRENT_TIMESTAMP'];
        $params = [];

        foreach (['name', 'slug', 'pin_to_dashboard', 'auto_save', 'sort_order'] as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[]   = "{$key} = ?";
            $params[] = $fields[$key];
        }

        if (count($sets) === 1) {
            return false;
        }

        $params[] = $id;
        $params[] = $userId;

        return $this->db->execute(
            'UPDATE user_clipboards SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_clipboards WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);

        return $this->db->fetchOne(
            'SELECT id, username, email FROM users WHERE username = ? OR email = ?',
            [$identifier, $identifier]
        );
    }
}
