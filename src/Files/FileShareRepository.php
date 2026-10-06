<?php

declare(strict_types=1);

namespace Dashboard\Files;

use Dashboard\Clipboard\ShareToken;
use Dashboard\DB\Database;

final class FileShareRepository
{
    private ?bool $tablesReady = null;

    public function __construct(private readonly Database $db) {}

    public function tablesReady(): bool
    {
        if ($this->tablesReady !== null) {
            return $this->tablesReady;
        }

        $this->tablesReady = $this->db->tableExists('file_share_links')
            && $this->db->tableExists('file_user_shares');

        return $this->tablesReady;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findLinkByToken(string $token): ?array
    {
        $token = ShareToken::normalize($token);

        return $this->db->fetchOne(
            "SELECT l.*, f.user_id AS owner_user_id
             FROM file_share_links l
             JOIN user_files f ON f.id = l.file_id
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
            'SELECT id FROM file_share_links WHERE token = ?',
            [$token]
        ) !== null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listLinksForFile(int $fileId): array
    {
        return $this->db->fetchAll(
            'SELECT id, token, label, expires_at, created_at, last_used_at, revoked
             FROM file_share_links
             WHERE file_id = ? AND revoked = 0
             ORDER BY created_at DESC',
            [$fileId]
        );
    }

    public function insertLink(int $fileId, string $token, ?string $label, ?string $expiresAt): int
    {
        $this->db->execute(
            'INSERT INTO file_share_links (file_id, token, label, expires_at)
             VALUES (?, ?, ?, ?)',
            [$fileId, $token, $label, $expiresAt]
        );

        return (int) $this->db->lastInsertId();
    }

    public function revokeLink(int $linkId, int $fileId): bool
    {
        return $this->db->execute(
            'UPDATE file_share_links SET revoked = 1 WHERE id = ? AND file_id = ?',
            [$linkId, $fileId]
        ) > 0;
    }

    public function touchLinkUsed(int $linkId): void
    {
        $this->db->execute(
            'UPDATE file_share_links SET last_used_at = CURRENT_TIMESTAMP WHERE id = ?',
            [$linkId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserShare(int $fileId, int $sharedWithUserId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM file_user_shares
             WHERE file_id = ? AND shared_with_user_id = ?',
            [$fileId, $sharedWithUserId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserSharesForFile(int $fileId): array
    {
        return $this->db->fetchAll(
            "SELECT s.id, s.created_at,
                    u.id AS user_id, u.username, u.email
             FROM file_user_shares s
             JOIN users u ON u.id = s.shared_with_user_id
             WHERE s.file_id = ?
             ORDER BY u.username ASC",
            [$fileId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listFilesSharedWithUser(int $userId): array
    {
        return $this->db->fetchAll(
            "SELECT f.*, s.id AS share_id, o.username AS owner_username
             FROM file_user_shares s
             JOIN user_files f ON f.id = s.file_id
             JOIN users o ON o.id = f.user_id
             WHERE s.shared_with_user_id = ?
             ORDER BY f.created_at DESC, f.id DESC",
            [$userId]
        );
    }

    public function insertUserShare(int $fileId, int $ownerUserId, int $sharedWithUserId): int
    {
        $this->db->execute(
            'INSERT INTO file_user_shares (file_id, owner_user_id, shared_with_user_id)
             VALUES (?, ?, ?)',
            [$fileId, $ownerUserId, $sharedWithUserId]
        );

        return (int) $this->db->lastInsertId();
    }

    public function deleteUserShare(int $shareId, int $fileId): bool
    {
        return $this->db->execute(
            'DELETE FROM file_user_shares WHERE id = ? AND file_id = ?',
            [$shareId, $fileId]
        ) > 0;
    }

    public function revokeAllForFile(int $fileId): void
    {
        $this->db->execute(
            'UPDATE file_share_links SET revoked = 1 WHERE file_id = ? AND revoked = 0',
            [$fileId]
        );
    }

    public function deleteAllUserSharesForFile(int $fileId): void
    {
        $this->db->execute(
            'DELETE FROM file_user_shares WHERE file_id = ?',
            [$fileId]
        );
    }
}
