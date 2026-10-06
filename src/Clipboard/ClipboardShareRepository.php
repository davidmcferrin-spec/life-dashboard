<?php

declare(strict_types=1);

namespace Dashboard\Clipboard;

use Dashboard\DB\Database;

final class ClipboardShareRepository
{
    private ?bool $tablesReady = null;

    public function __construct(private readonly Database $db) {}

    public function tablesReady(): bool
    {
        if ($this->tablesReady !== null) {
            return $this->tablesReady;
        }

        $this->tablesReady = $this->db->tableExists('clipboard_share_links')
            && $this->db->tableExists('clipboard_user_shares');

        return $this->tablesReady;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLinkByToken(string $token): ?array
    {
        $token = ShareToken::normalize($token);

        return $this->db->fetchOne(
            "SELECT l.*, c.user_id AS owner_user_id
             FROM clipboard_share_links l
             JOIN user_clipboards c ON c.id = l.clipboard_id
             WHERE l.token = ?
               AND l.revoked = 0
               AND (l.expires_at IS NULL OR l.expires_at > CURRENT_TIMESTAMP)",
            [$token]
        );
    }

    public function tokenExists(string $token): bool
    {
        $token = ShareToken::normalize($token);

        return $this->db->fetchOne(
            'SELECT id FROM clipboard_share_links WHERE token = ?',
            [$token]
        ) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLinksForClipboard(int $clipboardId): array
    {
        return $this->db->fetchAll(
            'SELECT id, token, permission, label, expires_at, created_at, last_used_at, revoked
             FROM clipboard_share_links
             WHERE clipboard_id = ? AND revoked = 0
             ORDER BY created_at DESC',
            [$clipboardId]
        );
    }

    public function insertLink(
        int $clipboardId,
        string $token,
        string $permission,
        ?string $label,
        ?string $expiresAt,
    ): int {
        $this->db->execute(
            'INSERT INTO clipboard_share_links (clipboard_id, token, permission, label, expires_at)
             VALUES (?, ?, ?, ?, ?)',
            [$clipboardId, $token, $permission, $label, $expiresAt]
        );

        return (int) $this->db->lastInsertId();
    }

    public function revokeLink(int $linkId, int $clipboardId): bool
    {
        return $this->db->execute(
            'UPDATE clipboard_share_links SET revoked = 1 WHERE id = ? AND clipboard_id = ?',
            [$linkId, $clipboardId]
        ) > 0;
    }

    public function touchLinkUsed(int $linkId): void
    {
        $this->db->execute(
            'UPDATE clipboard_share_links SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$linkId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserShare(int $clipboardId, int $sharedWithUserId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM clipboard_user_shares
             WHERE clipboard_id = ? AND shared_with_user_id = ?',
            [$clipboardId, $sharedWithUserId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserSharesForClipboard(int $clipboardId): array
    {
        return $this->db->fetchAll(
            "SELECT s.id, s.permission, s.created_at,
                    u.id AS user_id, u.username, u.email
             FROM clipboard_user_shares s
             JOIN users u ON u.id = s.shared_with_user_id
             WHERE s.clipboard_id = ?
             ORDER BY u.username ASC",
            [$clipboardId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listClipboardsSharedWithUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, s.permission AS share_permission, s.id AS share_id,
                    o.username AS owner_username
             FROM clipboard_user_shares s
             JOIN user_clipboards c ON c.id = s.clipboard_id
             JOIN users o ON o.id = c.user_id
             WHERE s.shared_with_user_id = ?
             ORDER BY c.name ASC",
            [$userId]
        );
    }

    public function insertUserShare(int $clipboardId, int $ownerUserId, int $sharedWithUserId, string $permission): int
    {
        $this->db->execute(
            'INSERT INTO clipboard_user_shares (clipboard_id, owner_user_id, shared_with_user_id, permission)
             VALUES (?, ?, ?, ?)',
            [$clipboardId, $ownerUserId, $sharedWithUserId, $permission]
        );

        return (int) $this->db->lastInsertId();
    }

    public function updateUserSharePermission(int $shareId, int $clipboardId, string $permission): bool
    {
        return $this->db->execute(
            'UPDATE clipboard_user_shares SET permission = ? WHERE id = ? AND clipboard_id = ?',
            [$permission, $shareId, $clipboardId]
        ) > 0;
    }

    public function deleteUserShare(int $shareId, int $clipboardId): bool
    {
        return $this->db->execute(
            'DELETE FROM clipboard_user_shares WHERE id = ? AND clipboard_id = ?',
            [$shareId, $clipboardId]
        ) > 0;
    }
}
