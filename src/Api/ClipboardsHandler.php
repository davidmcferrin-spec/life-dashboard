<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Clipboard\ClipboardService;
use Dashboard\Clipboard\ClipboardShareService;
use Dashboard\DB\Database;

/**
 * JSON API for cloud clipboards (v1: plain text).
 */
final class ClipboardsHandler
{
    private ClipboardService $clipboards;
    private ClipboardShareService $shares;

    public function __construct(Database $db)
    {
        $this->clipboards = ClipboardService::fromDatabase($db);
        $this->shares      = new ClipboardShareService($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        $userId     = (int) $user['id'];
        $pinnedOnly = isset($_GET['pinned']) && $_GET['pinned'] === '1';

        jsonResponse([
            'clipboards' => $this->clipboards->listSummaries($userId, $pinnedOnly),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function get(array $user, array $params): void
    {
        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid clipboard id.'], 400);
        }

        $sinceVersion = isset($_GET['since_version']) ? (int) $_GET['since_version'] : null;
        $board        = $this->clipboards->getBoard($id, $userId, $sinceVersion);

        if ($board === null) {
            jsonResponse(['error' => 'Clipboard not found.'], 404);
        }

        jsonResponse(['clipboard' => $board]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function create(array $user): void
    {
        validateCsrf();

        $body = $this->jsonBody();
        $name = trim((string) ($body['name'] ?? ''));
        $pin  = !empty($body['pin_to_dashboard']);

        try {
            $board = $this->clipboards->create((int) $user['id'], $name, $pin);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['clipboard' => $board], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function save(array $user, array $params): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid clipboard id.'], 400);
        }

        $body            = $this->jsonBody();
        $content         = (string) ($body['content'] ?? '');
        $expectedVersion = (int) ($body['version'] ?? 0);

        if ($expectedVersion < 1) {
            jsonResponse(['error' => 'Missing or invalid version.'], 422);
        }

        $result = $this->clipboards->saveContent($id, $userId, $content, $expectedVersion);

        if (($result['error'] ?? '') === 'not_found') {
            jsonResponse(['error' => 'Clipboard not found.'], 404);
        }

        if (($result['error'] ?? '') === 'forbidden') {
            jsonResponse(['error' => 'Read-only access.'], 403);
        }

        if (($result['error'] ?? '') === 'conflict') {
            jsonResponse([
                'error'     => 'conflict',
                'message'   => 'A newer version exists on the server.',
                'clipboard' => $result['board'],
            ], 409);
        }

        jsonResponse(['clipboard' => $result['board']]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function settings(array $user, array $params): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid clipboard id.'], 400);
        }

        $body = $this->jsonBody();
        $settings = [];

        if (array_key_exists('name', $body)) {
            $settings['name'] = (string) $body['name'];
        }
        if (array_key_exists('pin_to_dashboard', $body)) {
            $settings['pin_to_dashboard'] = (bool) $body['pin_to_dashboard'];
        }
        if (array_key_exists('auto_save', $body)) {
            $settings['auto_save'] = (bool) $body['auto_save'];
        }

        try {
            $board = $this->clipboards->updateSettings($id, $userId, $settings);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if ($board === null) {
            jsonResponse(['error' => 'Clipboard not found.'], 404);
        }

        jsonResponse(['clipboard' => $board]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function delete(array $user, array $params): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid clipboard id.'], 400);
        }

        if (!$this->clipboards->delete($id, $userId)) {
            jsonResponse(['error' => 'Clipboard not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function listShares(array $user, array $params): void
    {
        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid clipboard id.'], 400);
        }

        try {
            $shares = $this->shares->listSharesForOwner($id, $userId);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        }

        jsonResponse($shares);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function createShareLink(array $user, array $params): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);
        $body   = $this->jsonBody();

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid clipboard id.'], 400);
        }

        try {
            $link = $this->shares->createLink(
                $id,
                $userId,
                (string) ($body['permission'] ?? 'read'),
                trim((string) ($body['label'] ?? '')) ?: null,
                isset($body['expires_in']) ? (string) $body['expires_in'] : null,
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['link' => $link], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function revokeShareLink(array $user, array $params): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);
        $linkId = (int) ($params['linkId'] ?? 0);

        if (!$this->shares->revokeLink($id, $userId, $linkId)) {
            jsonResponse(['error' => 'Link not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function shareWithUser(array $user, array $params): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);
        $body   = $this->jsonBody();

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid clipboard id.'], 400);
        }

        try {
            $share = $this->shares->shareWithUser(
                $id,
                $userId,
                trim((string) ($body['identifier'] ?? '')),
                (string) ($body['permission'] ?? 'read'),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['share' => $share]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function removeUserShare(array $user, array $params): void
    {
        validateCsrf();

        $userId  = (int) $user['id'];
        $id      = (int) ($params['id'] ?? 0);
        $shareId = (int) ($params['shareId'] ?? 0);

        if (!$this->shares->removeUserShare($id, $userId, $shareId)) {
            jsonResponse(['error' => 'Share not found.'], 404);
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
