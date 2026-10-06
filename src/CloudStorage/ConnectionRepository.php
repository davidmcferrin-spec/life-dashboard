<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\DB\Database;

/**
 * Persistence for per-user cloud storage connections (one active row per user).
 */
final class ConnectionRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return array<string, mixed>|null
     */
    public function findByUserId(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_storage_connections WHERE user_id = ?',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByUserId(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_storage_connections WHERE user_id = ? AND is_active = 1',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_storage_connections WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_storage_connections WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            'INSERT INTO user_storage_connections
                (user_id, provider, credentials_encrypted, root_provider_path, root_provider_id,
                 is_active, migration_dismissed)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['user_id'],
                (string) $data['provider'],
                (string) $data['credentials_encrypted'],
                (string) $data['root_provider_path'],
                (string) $data['root_provider_id'],
                (int) ($data['is_active'] ?? 1),
                (int) ($data['migration_dismissed'] ?? 0),
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): bool
    {
        $sets   = [];
        $params = [];

        foreach ([
            'provider',
            'credentials_encrypted',
            'root_provider_path',
            'root_provider_id',
            'is_active',
            'disconnected_at',
            'migration_dismissed',
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
            'UPDATE user_storage_connections SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function disconnect(int $id, int $userId): bool
    {
        return $this->update($id, $userId, [
            'is_active'       => 0,
            'disconnected_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_storage_connections WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }
}
