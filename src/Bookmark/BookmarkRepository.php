<?php

declare(strict_types=1);

namespace Dashboard\Bookmark;

use Dashboard\DB\Database;

final class BookmarkRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function foldersForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM bookmark_folders WHERE user_id = ? ORDER BY sort_order ASC, name ASC',
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function bookmarksForUser(int $userId, ?int $folderId = null): array
    {
        if ($folderId === null) {
            return $this->db->fetchAll(
                'SELECT * FROM bookmarks WHERE user_id = ? AND folder_id IS NULL ORDER BY sort_order ASC, title ASC',
                [$userId]
            );
        }

        return $this->db->fetchAll(
            'SELECT * FROM bookmarks WHERE user_id = ? AND folder_id = ? ORDER BY sort_order ASC, title ASC',
            [$userId, $folderId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findFolder(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM bookmark_folders WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findBookmark(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM bookmarks WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function nextFolderSort(int $userId): int
    {
        $max = (int) $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM bookmark_folders WHERE user_id = ?',
            [$userId]
        );

        return $max + 1;
    }

    public function nextBookmarkSort(int $userId, ?int $folderId): int
    {
        if ($folderId === null) {
            $max = (int) $this->db->fetchScalar(
                'SELECT COALESCE(MAX(sort_order), -1) FROM bookmarks WHERE user_id = ? AND folder_id IS NULL',
                [$userId]
            );
        } else {
            $max = (int) $this->db->fetchScalar(
                'SELECT COALESCE(MAX(sort_order), -1) FROM bookmarks WHERE user_id = ? AND folder_id = ?',
                [$userId, $folderId]
            );
        }

        return $max + 1;
    }

    public function createFolder(int $userId, string $name, ?string $icon, int $sortOrder): int
    {
        $this->db->execute(
            'INSERT INTO bookmark_folders (user_id, name, icon, sort_order) VALUES (?, ?, ?, ?)',
            [$userId, $name, $icon, $sortOrder]
        );

        return (int) $this->db->lastInsertId();
    }

    public function updateFolder(int $id, int $userId, string $name, ?string $icon): bool
    {
        return $this->db->execute(
            'UPDATE bookmark_folders SET name = ?, icon = ? WHERE id = ? AND user_id = ?',
            [$name, $icon, $id, $userId]
        ) > 0;
    }

    public function deleteFolder(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM bookmark_folders WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    public function createBookmark(
        int $userId,
        ?int $folderId,
        string $title,
        string $url,
        ?string $faviconUrl,
        int $sortOrder,
    ): int {
        $this->db->execute(
            'INSERT INTO bookmarks (user_id, folder_id, title, url, favicon_url, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$userId, $folderId, $title, $url, $faviconUrl, $sortOrder]
        );

        return (int) $this->db->lastInsertId();
    }

    public function updateBookmark(
        int $id,
        int $userId,
        ?int $folderId,
        string $title,
        string $url,
        ?string $faviconUrl,
    ): bool {
        return $this->db->execute(
            'UPDATE bookmarks SET folder_id = ?, title = ?, url = ?, favicon_url = ?
             WHERE id = ? AND user_id = ?',
            [$folderId, $title, $url, $faviconUrl, $id, $userId]
        ) > 0;
    }

    public function deleteBookmark(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM bookmarks WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function allBookmarksForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM bookmarks WHERE user_id = ? ORDER BY sort_order ASC, title ASC',
            [$userId]
        );
    }

    public function updateFaviconExt(int $id, int $userId, ?string $ext): bool
    {
        return $this->db->execute(
            'UPDATE bookmarks SET favicon_ext = ?, favicon_url = NULL WHERE id = ? AND user_id = ?',
            [$ext, $id, $userId]
        ) > 0;
    }
}
