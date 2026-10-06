<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\DB\Database;
use Dashboard\Ping\PingService;

final class PingsHandler
{
    private PingService $pings;

    public function __construct(Database $db)
    {
        $this->pings = PingService::fromDatabase($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        jsonResponse(['targets' => $this->pings->listForUser((int) $user['id'])]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function create(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $target = $this->pings->create(
                (int) $user['id'],
                (string) ($body['label'] ?? ''),
                (string) ($body['target'] ?? ''),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['target' => $target], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function update(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid id.'], 400);
        }

        $target = $this->pings->update($id, (int) $user['id'], $this->jsonBody());
        if ($target === null) {
            jsonResponse(['error' => 'Target not found.'], 404);
        }

        jsonResponse(['target' => $target]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function delete(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0 || !$this->pings->delete($id, (int) $user['id'])) {
            jsonResponse(['error' => 'Target not found.'], 404);
        }

        jsonResponse(['ok' => true]);
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
