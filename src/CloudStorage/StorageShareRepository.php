<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

use Dashboard\Clipboard\ShareToken;
use Dashboard\Config;
use Dashboard\DB\Database;

final class StorageShareRepository
{
    public function __construct(private readonly Database $db) {}

    public function tablesReady(): bool
    {
        return $this->db->tableExists('storage_node_share_links')
            && $this->db->tableExists('storage_node_user_shares');
    }

    public function tokenExists(string $token): bool
    {
        $token = ShareToken::normalize($token);

        if ($this->db->fetchOne('SELECT id FROM file_share_links WHERE token = ?', [$token]) !== null) {
            return true;
        }

        return $this->db->fetchOne(
            'SELECT id FROM storage_node_share_links WHERE token = ?',
            [$token]
        ) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLinkByToken(string $token): ?array
    {
        $token = ShareToken::normalize($token);

        return $this->db->fetchOne(
            "SELECT l.*, n.user_id AS owner_user_id, c.is_active AS connection_active
             FROM storage_node_share_links l
             JOIN user_storage_nodes n ON n.id = l.storage_node_id
             LEFT JOIN user_storage_connections c ON c.id = n.connection_id
             WHERE l.token = ?
               AND l.revoked = 0
               AND (l.expires_at IS NULL OR l.expires_at > CURRENT_TIMESTAMP)",
            [$token]
        );
    }

    public function countActiveLinksForUser(int $userId): int
    {
        if (!$this->tablesReady()) {
            return 0;
        }

        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM storage_node_share_links l
             JOIN user_storage_nodes n ON n.id = l.storage_node_id
             WHERE n.user_id = ? AND l.revoked = 0',
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLinksForNode(int $nodeId): array
    {
        return $this->db->fetchAll(
            'SELECT id, token, label, expires_at, created_at, last_used_at, revoked
             FROM storage_node_share_links
             WHERE storage_node_id = ? AND revoked = 0
             ORDER BY created_at DESC',
            [$nodeId]
        );
    }

    public function insertLink(int $nodeId, string $token, ?string $label, ?string $expiresAt): int
    {
        $this->db->execute(
            'INSERT INTO storage_node_share_links (storage_node_id, token, label, expires_at)
             VALUES (?, ?, ?, ?)',
            [$nodeId, $token, $label, $expiresAt]
        );

        return (int) $this->db->lastInsertId();
    }

    public function revokeLink(int $linkId, int $nodeId): bool
    {
        return $this->db->execute(
            'UPDATE storage_node_share_links SET revoked = 1 WHERE id = ? AND storage_node_id = ?',
            [$linkId, $nodeId]
        ) > 0;
    }

    public function touchLinkUsed(int $linkId): void
    {
        $this->db->execute(
            'UPDATE storage_node_share_links SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$linkId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserSharesForNode(int $nodeId): array
    {
        return $this->db->fetchAll(
            "SELECT s.id, s.created_at, u.id AS user_id, u.username, u.email
             FROM storage_node_user_shares s
             JOIN users u ON u.id = s.shared_with_user_id
             WHERE s.storage_node_id = ?
             ORDER BY u.username ASC",
            [$nodeId]
        );
    }

    public function findUserShare(int $nodeId, int $sharedWithUserId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM storage_node_user_shares
             WHERE storage_node_id = ? AND shared_with_user_id = ?',
            [$nodeId, $sharedWithUserId]
        );
    }

    public function insertUserShare(int $nodeId, int $ownerUserId, int $sharedWithUserId): int
    {
        $this->db->execute(
            'INSERT INTO storage_node_user_shares (storage_node_id, owner_user_id, shared_with_user_id)
             VALUES (?, ?, ?)',
            [$nodeId, $ownerUserId, $sharedWithUserId]
        );

        return (int) $this->db->lastInsertId();
    }

    public function deleteUserShare(int $shareId, int $nodeId): bool
    {
        return $this->db->execute(
            'DELETE FROM storage_node_user_shares WHERE id = ? AND storage_node_id = ?',
            [$shareId, $nodeId]
        ) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSharedWithUser(int $userId): array
    {
        if (!$this->tablesReady()) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT n.id,
                    n.name AS original_name,
                    n.ext,
                    n.size_bytes,
                    n.mime_type,
                    u.username AS owner_username,
                    COALESCE(c.is_active, 0) AS connection_active,
                    'cloud' AS source
             FROM storage_node_user_shares s
             JOIN user_storage_nodes n ON n.id = s.storage_node_id
             JOIN users u ON u.id = s.owner_user_id
             LEFT JOIN user_storage_connections c ON c.id = n.connection_id
             WHERE s.shared_with_user_id = ?
               AND n.node_type = 'file'
             ORDER BY n.name ASC",
            [$userId]
        );
    }

    public function shareUrl(string $token): string
    {
        $base = rtrim((string) Config::get('APP_URL', ''), '/');

        return ($base !== '' ? $base : '') . '/share/file/' . $token;
    }
}
