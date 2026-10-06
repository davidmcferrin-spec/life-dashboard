<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\DB\Database;

/**
 * Persistence for cached cloud storage nodes mirrored from providers.
 */
final class NodeRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForConnection(int $connectionId, int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_storage_nodes
             WHERE connection_id = ? AND user_id = ?
             ORDER BY node_type DESC, name ASC, id ASC',
            [$connectionId, $userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_storage_nodes
             WHERE user_id = ?
             ORDER BY updated_at DESC, id DESC',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_storage_nodes WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_storage_nodes WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByProviderPath(int $connectionId, string $providerPath): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_storage_nodes
             WHERE connection_id = ? AND provider_path = ?',
            [$connectionId, $providerPath]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $this->db->execute(
            'INSERT INTO user_storage_nodes
                (user_id, connection_id, node_type, name, provider_node_id, provider_path,
                 mime_type, size_bytes, ext)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $data['user_id'],
                (int) $data['connection_id'],
                (string) $data['node_type'],
                (string) $data['name'],
                (string) $data['provider_node_id'],
                (string) $data['provider_path'],
                $data['mime_type'] ?? null,
                $data['size_bytes'] ?? null,
                $data['ext'] ?? null,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): bool
    {
        $sets   = ['updated_at = CURRENT_TIMESTAMP'];
        $params = [];

        foreach ([
            'node_type',
            'name',
            'provider_node_id',
            'provider_path',
            'mime_type',
            'size_bytes',
            'ext',
        ] as $key) {
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
            'UPDATE user_storage_nodes SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_storage_nodes WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    public function deleteForConnection(int $connectionId, int $userId): int
    {
        return $this->db->execute(
            'DELETE FROM user_storage_nodes WHERE connection_id = ? AND user_id = ?',
            [$connectionId, $userId]
        );
    }
}
