<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Clipboard\ClipboardService;
use Dashboard\Clipboard\ClipboardShareService;
use Dashboard\DB\Database;
use Dashboard\Share\ShareDeniedResponse;

/**
 * Public share-link access to a single clipboard (no login).
 */
final class SharedClipboardHandler
{
    private ClipboardService $clipboards;
    private ClipboardShareService $shares;

    public function __construct(Database $db)
    {
        $this->clipboards = ClipboardService::fromDatabase($db);
        $this->shares      = new ClipboardShareService($db);
    }

    /**
     * @param array<string, string> $params
     */
    public function get(array $params): void
    {
        $token  = (string) ($params['token'] ?? '');
        $access = $this->clipboards->resolveLinkAccess($token);

        if ($access === null) {
            ShareDeniedResponse::json();
        }

        $sinceVersion = isset($_GET['since_version']) ? (int) $_GET['since_version'] : null;
        $board        = $this->clipboards->getBoardForAccess($access, $sinceVersion);

        jsonResponse([
            'clipboard' => $board,
            'permission' => $access->permission,
            'can_write'  => $access->canWrite(),
        ]);
    }

    /**
     * @param array<string, string> $params
     */
    public function save(array $params): void
    {
        $token  = (string) ($params['token'] ?? '');
        $access = $this->clipboards->resolveLinkAccess($token);

        if ($access === null) {
            ShareDeniedResponse::json();
        }

        if (!$access->canWrite()) {
            ShareDeniedResponse::json();
        }

        $body            = $this->jsonBody();
        $content         = (string) ($body['content'] ?? '');
        $expectedVersion = (int) ($body['version'] ?? 0);

        if ($expectedVersion < 1) {
            jsonResponse(['error' => 'Missing or invalid version.'], 422);
        }

        $result = $this->clipboards->saveContentForAccess($access, $content, $expectedVersion);

        if (($result['error'] ?? '') === 'conflict') {
            jsonResponse([
                'error'     => 'conflict',
                'message'   => 'A newer version exists on the server.',
                'clipboard' => $result['board'],
            ], 409);
        }

        $this->shares->touchLinkByToken($token);

        jsonResponse(['clipboard' => $result['board']]);
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
