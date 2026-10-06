<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\CloudPad\CloudPadService;
use Dashboard\CloudPad\CloudPadShareService;
use Dashboard\DB\Database;

final class CloudPadHandler
{
    private CloudPadService $cloudpad;
    private CloudPadShareService $shares;

    public function __construct(Database $db)
    {
        $this->cloudpad = CloudPadService::fromDatabase($db);
        $this->shares   = new CloudPadShareService($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        jsonResponse([
            'canvases'    => $this->cloudpad->listSummaries((int) $user['id']),
            'max_canvases' => CloudPadService::maxCanvases(),
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
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        $sinceVersion = isset($_GET['since_version']) ? (int) $_GET['since_version'] : null;
        $canvas       = $this->cloudpad->getCanvas($id, $userId, $sinceVersion);

        if ($canvas === null) {
            jsonResponse(['error' => 'Canvas not found.'], 404);
        }

        jsonResponse(['canvas' => $canvas]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function create(array $user): void
    {
        validateCsrf();

        $body = $this->jsonBody();
        $name = trim((string) ($body['name'] ?? ''));

        try {
            $canvas = $this->cloudpad->create((int) $user['id'], $name);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['canvas' => $canvas], 201);
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
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        $body            = $this->jsonBody();
        $content         = (string) ($body['content'] ?? '');
        $expectedVersion = (int) ($body['version'] ?? 0);

        if ($expectedVersion < 1) {
            jsonResponse(['error' => 'Missing or invalid version.'], 422);
        }

        try {
            $result = $this->cloudpad->saveContent($id, $userId, $content, $expectedVersion);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if (($result['error'] ?? '') === 'not_found') {
            jsonResponse(['error' => 'Canvas not found.'], 404);
        }

        if (($result['error'] ?? '') === 'forbidden') {
            jsonResponse(['error' => 'Read-only access.'], 403);
        }

        if (($result['error'] ?? '') === 'no_control') {
            jsonResponse(['error' => 'You do not have edit control.'], 403);
        }

        if (($result['error'] ?? '') === 'conflict') {
            jsonResponse([
                'error'   => 'conflict',
                'message' => 'A newer version exists on the server.',
                'canvas'  => $result['canvas'],
            ], 409);
        }

        jsonResponse(['canvas' => $result['canvas']]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function update(array $user, array $params): void
    {
        validateCsrf();

        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        $body = $this->jsonBody();
        $name = trim((string) ($body['name'] ?? ''));

        try {
            $canvas = $this->cloudpad->updateName($id, $userId, $name);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if ($canvas === null) {
            jsonResponse(['error' => 'Canvas not found.'], 404);
        }

        jsonResponse(['canvas' => $canvas]);
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

        if ($id <= 0 || !$this->cloudpad->delete($id, $userId)) {
            jsonResponse(['error' => 'Canvas not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function controlStatus(array $user, array $params): void
    {
        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        $control = $this->cloudpad->control()->getState($id, $userId);

        if ($control === null) {
            jsonResponse(['error' => 'Canvas not found.'], 404);
        }

        jsonResponse(['control' => $control]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function controlTake(array $user, array $params): void
    {
        validateCsrf();
        $this->controlAction($user, $params, 'take');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function controlRequest(array $user, array $params): void
    {
        validateCsrf();
        $this->controlAction($user, $params, 'request');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function controlGrant(array $user, array $params): void
    {
        validateCsrf();
        $this->controlAction($user, $params, 'grant');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function controlDeny(array $user, array $params): void
    {
        validateCsrf();
        $this->controlAction($user, $params, 'deny');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function controlRelease(array $user, array $params): void
    {
        validateCsrf();
        $this->controlAction($user, $params, 'release');
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function controlHeartbeat(array $user, array $params): void
    {
        validateCsrf();
        $this->controlAction($user, $params, 'heartbeat');
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
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        try {
            $shares = $this->shares->listSharesForOwner($id, $userId);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
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

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        $body      = $this->jsonBody();
        $label     = trim((string) ($body['label'] ?? ''));
        $expiresIn = isset($body['expires_in']) ? (string) $body['expires_in'] : null;

        try {
            $link = $this->shares->createLink($id, $userId, $label ?: null, $expiresIn);
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

        if ($id <= 0 || $linkId <= 0 || !$this->shares->revokeLink($id, $userId, $linkId)) {
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

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        $body       = $this->jsonBody();
        $identifier = trim((string) ($body['identifier'] ?? ''));
        $permission = (string) ($body['permission'] ?? 'read');

        try {
            $share = $this->shares->shareWithUser($id, $userId, $identifier, $permission);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['share' => $share], 201);
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

        if ($id <= 0 || $shareId <= 0 || !$this->shares->removeUserShare($id, $userId, $shareId)) {
            jsonResponse(['error' => 'Share not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    private function controlAction(array $user, array $params, string $action): void
    {
        $userId = (int) $user['id'];
        $id     = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid canvas id.'], 400);
        }

        $body  = $this->jsonBody();
        $force = !empty($body['force']);

        $control = $this->cloudpad->control();

        $result = match ($action) {
            'take'      => $control->take($id, $userId, $force),
            'request'   => $control->request($id, $userId),
            'grant'     => $control->grant($id, $userId),
            'deny'      => $control->deny($id, $userId),
            'release'   => $control->release($id, $userId),
            'heartbeat' => $control->heartbeat($id, $userId),
            default     => ['error' => 'unknown'],
        };

        $error = $result['error'] ?? '';
        if ($error === 'not_found') {
            jsonResponse(['error' => 'Canvas not found.'], 404);
        }
        if ($error === 'forbidden') {
            jsonResponse(['error' => 'Not allowed.'], 403);
        }
        if ($error === 'locked') {
            jsonResponse(['error' => 'Someone else is editing. Request control or wait.'], 409);
        }
        if ($error === 'no_request') {
            jsonResponse(['error' => 'No pending control request.'], 422);
        }
        if ($error === 'not_holder') {
            jsonResponse(['error' => 'You do not hold edit control.'], 403);
        }

        jsonResponse(['control' => $result['control']]);
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
