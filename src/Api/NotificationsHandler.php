<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Notification\NotificationService;

final class NotificationsHandler
{
    private NotificationService $notifications;

    public function __construct(Database $db)
    {
        $this->notifications = new NotificationService($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        jsonResponse($this->notifications->list((int) $user['id']));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function create(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();
        try {
            $item = $this->notifications->create(
                (int) $user['id'],
                (string) ($body['title'] ?? ''),
                (string) ($body['body'] ?? ''),
                isset($body['href']) ? (string) $body['href'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['notification' => $item], 201);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function markAll(array $user): void
    {
        validateCsrf();
        jsonResponse($this->notifications->markAllRead((int) $user['id']));
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function markRead(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);
        $item = $this->notifications->markRead((int) $user['id'], $id);
        if ($item === null) {
            jsonResponse(['error' => 'Notification not found.'], 404);
        }

        jsonResponse(['notification' => $item]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
