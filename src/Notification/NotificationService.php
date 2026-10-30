<?php

declare(strict_types=1);

namespace Dashboard\Notification;

use Dashboard\DB\Database;
use Dashboard\Support\LocalPath;

final class NotificationService
{
    public const LIST_LIMIT = 30;

    public const KEEP = 100;

    public function __construct(private readonly Database $db) {}

    /**
     * @return array{notifications: list<array<string, mixed>>, unread: int}
     */
    public function list(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, title, body, href, read_at, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ?',
            [$userId, self::LIST_LIMIT]
        );
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->present($row);
        }

        return [
            'notifications' => $items,
            'unread'        => $this->unreadCount($userId),
        ];
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function create(int $userId, string $title, string $body = '', ?string $href = null): array
    {
        $title = trim($title);
        $length = function_exists('mb_strlen') ? mb_strlen($title) : strlen($title);
        if ($title === '' || $length > 160) {
            throw new \InvalidArgumentException('Title must be 1 to 160 characters.');
        }
        $body = trim($body);
        $bodyLength = function_exists('mb_strlen') ? mb_strlen($body) : strlen($body);
        if ($bodyLength > 2000) {
            throw new \InvalidArgumentException('Body must be 2000 characters or fewer.');
        }
        $storedHref = $this->href($href);
        $row = $this->db->fetchOne(
            'INSERT INTO notifications (user_id, title, body, href)
             VALUES (?, ?, ?, ?)
             RETURNING id, title, body, href, read_at, created_at',
            [$userId, $title, $body, $storedHref]
        );
        if ($row === null) {
            throw new \RuntimeException('Failed to save notification.');
        }
        $this->trim($userId);

        return $this->present($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function markRead(int $userId, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $this->db->execute(
            "UPDATE notifications
             SET read_at = (now() AT TIME ZONE 'UTC')
             WHERE user_id = ? AND id = ? AND read_at IS NULL",
            [$userId, $id]
        );
        $row = $this->db->fetchOne(
            'SELECT id, title, body, href, read_at, created_at
             FROM notifications WHERE user_id = ? AND id = ?',
            [$userId, $id]
        );

        return $row === null ? null : $this->present($row);
    }

    /**
     * @return array{unread: int}
     */
    public function markAllRead(int $userId): array
    {
        $this->db->execute(
            "UPDATE notifications
             SET read_at = (now() AT TIME ZONE 'UTC')
             WHERE user_id = ? AND read_at IS NULL",
            [$userId]
        );

        return ['unread' => 0];
    }

    private function href(?string $href): ?string
    {
        if ($href === null || trim($href) === '') {
            return null;
        }
        $path = LocalPath::next(trim($href));
        if ($path === null) {
            throw new \InvalidArgumentException('Link must be a path on this dashboard.');
        }

        return $path;
    }

    private function trim(int $userId): void
    {
        // LIMIT is the KEEP constant, inlined so sql-lint can prepare the statement.
        $this->db->execute(
            'DELETE FROM notifications
             WHERE user_id = ?
               AND id NOT IN (
                   SELECT id FROM notifications
                   WHERE user_id = ?
                   ORDER BY created_at DESC, id DESC
                   LIMIT 100
               )',
            [$userId, $userId]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $href = $row['href'] !== null && (string) $row['href'] !== '' ? (string) $row['href'] : null;

        return [
            'id'         => (int) $row['id'],
            'title'      => (string) $row['title'],
            'body'       => (string) $row['body'],
            'href'       => $href,
            'read'       => $row['read_at'] !== null && (string) $row['read_at'] !== '',
            'created_at' => $this->stamp((string) $row['created_at']),
        ];
    }

    private function stamp(string $value): string
    {
        $value = str_replace(' ', 'T', $value);

        return str_ends_with($value, 'Z') ? $value : $value . 'Z';
    }
}
