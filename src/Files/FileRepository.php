<?php

declare(strict_types=1);

namespace Dashboard\Files;

use Dashboard\DB\Database;

final class FileRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_files WHERE user_id = ? ORDER BY created_at DESC, id DESC',
            [$userId]
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_files WHERE user_id = ?',
            [$userId]
        );
    }

    public function totalBytesForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COALESCE(SUM(size_bytes), 0) FROM user_files WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_files WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_files WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            'INSERT INTO user_files (user_id, original_name, storage_id, mime_type, ext, size_bytes)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $data['user_id'],
                (string) $data['original_name'],
                (string) $data['storage_id'],
                (string) $data['mime_type'],
                (string) $data['ext'],
                (int) $data['size_bytes'],
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_files WHERE id = ? AND user_id = ?',
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
