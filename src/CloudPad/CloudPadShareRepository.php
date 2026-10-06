<?php

declare(strict_types=1);

namespace Dashboard\CloudPad;

use Dashboard\Clipboard\ShareToken;
use Dashboard\DB\Database;

final class CloudPadShareRepository
{
    private ?bool $tablesReady = null;

    public function __construct(private readonly Database $db) {}

    public function tablesReady(): bool
    {
        if ($this->tablesReady !== null) {
            return $this->tablesReady;
        }

        $this->tablesReady = $this->db->tableExists('cloudpad_share_links')
            && $this->db->tableExists('cloudpad_user_shares');

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
             FROM cloudpad_share_links l
             JOIN user_cloudpad_canvases c ON c.id = l.canvas_id
             WHERE l.token = ?
               AND l.revoked = 0
               AND (l.expires_at IS NULL OR l.expires_at > CURRENT_TIMESTAMP)",
            [$token],
        );
    }

    public function tokenExists(string $token): bool
    {
        $token = ShareToken::normalize($token);

        return $this->db->fetchOne(
            'SELECT id FROM cloudpad_share_links WHERE token = ?',
            [$token],
        ) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLinksForCanvas(int $canvasId): array
    {
        return $this->db->fetchAll(
            'SELECT id, token, permission, label, expires_at, created_at, last_used_at, revoked
             FROM cloudpad_share_links
             WHERE canvas_id = ? AND revoked = 0
             ORDER BY created_at DESC',
            [$canvasId],
        );
    }

    public function insertLink(
        int $canvasId,
        string $token,
        string $permission,
        ?string $label,
        ?string $expiresAt,
    ): int {
        $this->db->execute(
            'INSERT INTO cloudpad_share_links (canvas_id, token, permission, label, expires_at)
             VALUES (?, ?, ?, ?, ?)',
            [$canvasId, $token, $permission, $label, $expiresAt],
        );

        return (int) $this->db->lastInsertId();
    }

    public function revokeLink(int $linkId, int $canvasId): bool
    {
        return $this->db->execute(
            'UPDATE cloudpad_share_links SET revoked = 1 WHERE id = ? AND canvas_id = ?',
            [$linkId, $canvasId],
        ) > 0;
    }

    public function touchLinkUsed(int $linkId): void
    {
        $this->db->execute(
            'UPDATE cloudpad_share_links SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$linkId],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserShare(int $canvasId, int $sharedWithUserId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM cloudpad_user_shares WHERE canvas_id = ? AND shared_with_user_id = ?',
            [$canvasId, $sharedWithUserId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserSharesForCanvas(int $canvasId): array
    {
        return $this->db->fetchAll(
            "SELECT s.id, s.permission, s.created_at,
                    u.id AS user_id, u.username, u.email
             FROM cloudpad_user_shares s
             JOIN users u ON u.id = s.shared_with_user_id
             WHERE s.canvas_id = ?
             ORDER BY u.username ASC",
            [$canvasId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listCanvasesSharedWithUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT c.*, s.permission AS share_permission, s.id AS share_id,
                    o.username AS owner_username
             FROM cloudpad_user_shares s
             JOIN user_cloudpad_canvases c ON c.id = s.canvas_id
             JOIN users o ON o.id = c.user_id
             WHERE s.shared_with_user_id = ?
             ORDER BY c.name ASC",
            [$userId],
        );
    }

    public function insertUserShare(int $canvasId, int $ownerUserId, int $sharedWithUserId, string $permission): int
    {
        $this->db->execute(
            'INSERT INTO cloudpad_user_shares (canvas_id, owner_user_id, shared_with_user_id, permission)
             VALUES (?, ?, ?, ?)',
            [$canvasId, $ownerUserId, $sharedWithUserId, $permission],
        );

        return (int) $this->db->lastInsertId();
    }

    public function updateUserSharePermission(int $shareId, int $canvasId, string $permission): bool
    {
        return $this->db->execute(
            'UPDATE cloudpad_user_shares SET permission = ? WHERE id = ? AND canvas_id = ?',
            [$permission, $shareId, $canvasId],
        ) > 0;
    }

    public function deleteUserShare(int $shareId, int $canvasId): bool
    {
        return $this->db->execute(
            'DELETE FROM cloudpad_user_shares WHERE id = ? AND canvas_id = ?',
            [$shareId, $canvasId],
        ) > 0;
    }
}
