<?php

declare(strict_types=1);

namespace Dashboard\Todo;

use Dashboard\DB\Database;

final class TodoRepository
{
    public function __construct(private readonly Database $db) {}

    public function tablesReady(): bool
    {
        return $this->db->tableExists('user_todo_lists')
            && $this->db->tableExists('user_todo_items');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findListById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_todo_lists WHERE id = ?',
            [$id]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findListByIdForOwner(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_todo_lists WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function countOwnedLists(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_todo_lists WHERE user_id = ?',
            [$userId]
        );
    }

    public function countPinnedForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_todo_lists WHERE user_id = ? AND pin_to_dashboard = 1',
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOwnedForUser(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_todo_lists WHERE user_id = ? ORDER BY sort_order ASC, name ASC',
            [$userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSharedWithUser(int $userId): array
    {
        if (!$this->db->tableExists('todo_list_user_shares')) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT l.*, s.permission AS share_permission, s.id AS share_id,
                    o.username AS owner_username
             FROM todo_list_user_shares s
             JOIN user_todo_lists l ON l.id = s.list_id
             JOIN users o ON o.id = l.user_id
             WHERE s.shared_with_user_id = ?
             ORDER BY l.name ASC",
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserShare(int $listId, int $userId): ?array
    {
        if (!$this->db->tableExists('todo_list_user_shares')) {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT * FROM todo_list_user_shares WHERE list_id = ? AND shared_with_user_id = ?',
            [$listId, $userId]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUserSharesForList(int $listId): array
    {
        if (!$this->db->tableExists('todo_list_user_shares')) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT s.*, u.username, u.email
             FROM todo_list_user_shares s
             JOIN users u ON u.id = s.shared_with_user_id
             WHERE s.list_id = ?
             ORDER BY u.username ASC",
            [$listId]
        );
    }

    public function insertUserShare(int $listId, int $ownerId, int $sharedWithUserId, string $permission): int
    {
        $this->db->execute(
            'INSERT INTO todo_list_user_shares (list_id, owner_user_id, shared_with_user_id, permission)
             VALUES (?, ?, ?, ?)',
            [$listId, $ownerId, $sharedWithUserId, $permission]
        );

        return (int) $this->db->lastInsertId();
    }

    public function updateUserSharePermission(int $shareId, int $listId, string $permission): bool
    {
        return $this->db->execute(
            'UPDATE todo_list_user_shares SET permission = ? WHERE id = ? AND list_id = ?',
            [$permission, $shareId, $listId]
        ) > 0;
    }

    public function deleteUserShare(int $shareId, int $listId): bool
    {
        return $this->db->execute(
            'DELETE FROM todo_list_user_shares WHERE id = ? AND list_id = ?',
            [$shareId, $listId]
        ) > 0;
    }

    /**
     * @return array{id: int, username: string, email: string}|null
     */
    public function findUserById(int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, username, email FROM users WHERE id = ?',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findUserByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '') {
            return null;
        }

        return $this->db->fetchOne(
            'SELECT id, username, email FROM users WHERE username = ? OR email = ? LIMIT 1',
            [$identifier, $identifier]
        );
    }

    public function slugExists(int $userId, string $slug, ?int $excludeId = null): bool
    {
        $sql    = 'SELECT id FROM user_todo_lists WHERE user_id = ? AND slug = ?';
        $params = [$userId, $slug];

        if ($excludeId !== null) {
            $sql .= ' AND id != ?';
            $params[] = $excludeId;
        }

        return $this->db->fetchOne($sql, $params) !== null;
    }

    public function nextListSortOrder(int $userId): int
    {
        $max = $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM user_todo_lists WHERE user_id = ?',
            [$userId]
        );

        return (int) $max + 1;
    }

    public function insertList(int $userId, string $name, string $slug, int $sortOrder, bool $pin): int
    {
        $this->db->execute(
            'INSERT INTO user_todo_lists (user_id, name, slug, sort_order, pin_to_dashboard)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $name, $slug, $sortOrder, $pin ? 1 : 0]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateList(int $id, int $userId, array $fields): bool
    {
        if ($fields === []) {
            return false;
        }

        $sets   = [];
        $params = [];

        foreach ($fields as $key => $value) {
            $sets[]   = "{$key} = ?";
            $params[] = $value;
        }

        $sets[]   = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $id;
        $params[] = $userId;

        return $this->db->execute(
            'UPDATE user_todo_lists SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function deleteList(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_todo_lists WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }

    /**
     * @return array<string, int>
     */
    public function countItemsByStatus(int $listId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT status, COUNT(*) AS cnt FROM user_todo_items
             WHERE list_id = ? GROUP BY status",
            [$listId]
        );

        $out = ['open' => 0, 'completed' => 0, 'archived' => 0];
        foreach ($rows as $row) {
            $status = (string) $row['status'];
            if (isset($out[$status])) {
                $out[$status] = (int) $row['cnt'];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listItems(int $listId, ?string $status = null, ?int $limit = null): array
    {
        $sql    = 'SELECT i.*, u.username AS assignee_username
                   FROM user_todo_items i
                   LEFT JOIN users u ON u.id = i.assigned_to_user_id
                   WHERE i.list_id = ?';
        $params = [$listId];

        if ($status !== null && $status !== 'all') {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY i.due_date IS NULL, i.due_date ASC, i.sort_order ASC, i.id ASC';

        if ($limit !== null) {
            $sql .= ' LIMIT ' . max(1, $limit);
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Owned lists only. status all is open plus completed. Archived stays out.
     *
     * @param array{category?: ?string, status?: string, due_before?: ?string, due_after?: ?string, priority?: int|null, limit?: int} $filters
     * @return list<array<string, mixed>>
     */
    public function searchOwned(int $userId, array $filters): array
    {
        $sql = 'SELECT i.*, l.name AS list_name, u.username AS assignee_username
                FROM user_todo_items i
                JOIN user_todo_lists l ON l.id = i.list_id
                LEFT JOIN users u ON u.id = i.assigned_to_user_id
                WHERE l.user_id = ? AND i.status <> \'archived\'';
        $params = [$userId];
        $status = $filters['status'] ?? 'open';
        if ($status === 'open' || $status === 'completed') {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }
        $category = trim((string) ($filters['category'] ?? ''));
        if ($category !== '') {
            $sql .= ' AND lower(l.name) = lower(?)';
            $params[] = $category;
        }
        $before = $filters['due_before'] ?? null;
        if (is_string($before) && $before !== '') {
            $sql .= ' AND i.due_date IS NOT NULL AND i.due_date <= ?';
            $params[] = $before;
        }
        $after = $filters['due_after'] ?? null;
        if (is_string($after) && $after !== '') {
            $sql .= ' AND i.due_date IS NOT NULL AND i.due_date >= ?';
            $params[] = $after;
        }
        if (isset($filters['priority']) && $filters['priority'] !== null) {
            $sql .= ' AND i.priority = ?';
            $params[] = (int) $filters['priority'];
        }
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $sql .= ' ORDER BY i.priority NULLS LAST, i.due_date NULLS LAST, i.sort_order ASC, i.id ASC LIMIT ' . $limit;
        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAssignedToUser(int $userId, ?string $status = 'open'): array
    {
        $sql    = "SELECT i.*, l.name AS list_name, l.id AS list_id, l.user_id AS list_owner_id,
                          o.username AS owner_username, u.username AS assignee_username
                   FROM user_todo_items i
                   JOIN user_todo_lists l ON l.id = i.list_id
                   JOIN users o ON o.id = l.user_id
                   LEFT JOIN users u ON u.id = i.assigned_to_user_id
                   WHERE i.assigned_to_user_id = ?";
        $params = [$userId];

        if ($status !== null && $status !== 'all') {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }

        $sql .= ' ORDER BY i.due_date IS NULL, i.due_date ASC, i.sort_order ASC, i.id ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findItemById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT i.*, l.user_id AS list_owner_id
             FROM user_todo_items i
             JOIN user_todo_lists l ON l.id = i.list_id
             WHERE i.id = ?',
            [$id]
        );
    }

    public function countOpenItems(int $listId): int
    {
        return (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM user_todo_items WHERE list_id = ? AND status = 'open'",
            [$listId]
        );
    }

    public function nextItemSortOrder(int $listId): int
    {
        $max = $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM user_todo_items WHERE list_id = ?',
            [$listId]
        );

        return (int) $max + 1;
    }

    public function insertItem(
        int $listId,
        string $title,
        ?string $notes,
        ?string $linksJson,
        int $createdByUserId,
        ?int $assignedToUserId,
        ?int $assignedByUserId,
        int $sortOrder,
        ?string $dueDate = null,
        ?int $priority = null,
    ): int {
        $this->db->execute(
            'INSERT INTO user_todo_items
             (list_id, title, notes, links_json, status, assigned_to_user_id, assigned_by_user_id,
              created_by_user_id, sort_order, due_date, priority)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $listId,
                $title,
                $notes,
                $linksJson,
                'open',
                $assignedToUserId,
                $assignedByUserId,
                $createdByUserId,
                $sortOrder,
                $dueDate,
                $priority,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function updateItem(int $id, array $fields): bool
    {
        if ($fields === []) {
            return false;
        }

        $sets   = [];
        $params = [];

        foreach ($fields as $key => $value) {
            $sets[]   = "{$key} = ?";
            $params[] = $value;
        }

        $sets[]   = 'updated_at = CURRENT_TIMESTAMP';
        $params[] = $id;

        return $this->db->execute(
            'UPDATE user_todo_items SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params
        ) > 0;
    }

    public function deleteItem(int $id): bool
    {
        return $this->db->execute('DELETE FROM user_todo_items WHERE id = ?', [$id]) > 0;
    }

    public function archiveCompletedForList(int $listId): int
    {
        return $this->db->execute(
            "UPDATE user_todo_items
             SET status = 'archived', archived_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE list_id = ? AND status = 'completed'",
            [$listId]
        );
    }

    /**
     * @return list<int>
     */
    public function listShareRecipientIds(int $listId): array
    {
        if (!$this->db->tableExists('todo_list_user_shares')) {
            return [];
        }

        $rows = $this->db->fetchAll(
            'SELECT shared_with_user_id FROM todo_list_user_shares WHERE list_id = ?',
            [$listId]
        );

        return array_map(static fn (array $r): int => (int) $r['shared_with_user_id'], $rows);
    }
}
