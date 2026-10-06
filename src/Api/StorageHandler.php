<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\CloudStorage\NodeRepository;
use Dashboard\CloudStorage\StorageConnectionService;
use Dashboard\CloudStorage\StorageService;
use Dashboard\CloudStorage\StorageShareService;
use Dashboard\CloudStorage\UserDropboxAppService;
use Dashboard\DB\Database;

final class StorageHandler
{
    private StorageConnectionService $connections;
    private StorageService $storage;
    private StorageShareService $shares;

    public function __construct(private readonly Database $db)
    {
        $this->connections = new StorageConnectionService($db);
        $this->storage     = new StorageService($db);
        $this->shares      = new StorageShareService($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function connectionStatus(array $user): void
    {
        jsonResponse($this->connections->status((int) $user['id']));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function browse(array $user): void
    {
        $path   = isset($_GET['path']) ? (string) $_GET['path'] : null;
        $cursor = isset($_GET['cursor']) ? (string) $_GET['cursor'] : null;

        try {
            jsonResponse($this->storage->browse((int) $user['id'], $path, $cursor));
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function upload(array $user): void
    {
        validateCsrf();

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)) {
            jsonResponse(['error' => 'No file uploaded.'], 422);
        }

        $path = (string) ($_POST['path'] ?? '');

        try {
            $item = $this->storage->upload((int) $user['id'], $path, $file);
            jsonResponse(['item' => $item], 201);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            jsonResponse(['error' => 'Upload failed.'], 500);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function createFolder(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();
        $path = (string) ($body['path'] ?? '');
        $name = trim((string) ($body['name'] ?? ''));

        try {
            $item = $this->storage->createFolder((int) $user['id'], $path, $name);
            jsonResponse(['item' => $item], 201);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function download(array $user, array $params): void
    {
        $nodeId = (int) ($params['id'] ?? 0);
        if ($nodeId <= 0) {
            jsonResponse(['error' => 'Invalid node id.'], 400);
        }

        try {
            $result = $this->storage->downloadByNodeId($nodeId, (int) $user['id']);
            $this->streamMeta($result['meta'], $result['inline']);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function deleteNode(array $user, array $params): void
    {
        validateCsrf();
        $nodeId = (int) ($params['id'] ?? 0);

        try {
            $this->storage->deleteNode($nodeId, (int) $user['id']);
            jsonResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function connectS3(array $user): void
    {
        validateCsrf();

        try {
            $this->connections->connectS3((int) $user['id'], $this->jsonBody());
            jsonResponse(['ok' => true, 'status' => $this->connections->status((int) $user['id'])]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function saveDropboxApp(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();
        $apps = new UserDropboxAppService($this->db);

        try {
            $apps->save(
                (int) $user['id'],
                trim((string) ($body['app_key'] ?? '')),
                (string) ($body['app_secret'] ?? ''),
            );
            jsonResponse([
                'ok'     => true,
                'message' => 'Dropbox app credentials saved.',
                'dropbox_app' => $apps->publicStatus((int) $user['id']),
                'status' => $this->connections->status((int) $user['id']),
            ]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function testDropboxApp(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();
        $apps = new UserDropboxAppService($this->db);

        $result = $apps->test((int) $user['id'], [
            'app_key'    => trim((string) ($body['app_key'] ?? '')),
            'app_secret' => (string) ($body['app_secret'] ?? ''),
        ]);

        jsonResponse($result, $result['ok'] ? 200 : 422);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function clearDropboxApp(array $user): void
    {
        validateCsrf();
        (new UserDropboxAppService($this->db))->clear((int) $user['id']);
        jsonResponse([
            'ok'     => true,
            'status' => $this->connections->status((int) $user['id']),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function disconnect(array $user): void
    {
        validateCsrf();
        $this->connections->disconnect((int) $user['id']);
        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function disconnectStats(array $user): void
    {
        jsonResponse($this->connections->disconnectStats((int) $user['id']));
    }

    /**
     * @param array<string, mixed> $user
     */
    public function dismissMigration(array $user): void
    {
        validateCsrf();
        $this->connections->dismissMigration((int) $user['id']);
        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function listSharedWithMe(array $user): void
    {
        jsonResponse([
            'files' => $this->shares->listSharedWithUser((int) $user['id']),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function revokeShareLink(array $user, array $params): void
    {
        validateCsrf();
        $nodeId = (int) ($params['id'] ?? 0);
        $linkId = (int) ($params['linkId'] ?? 0);

        try {
            $this->shares->revokeLink($nodeId, (int) $user['id'], $linkId);
            jsonResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function shareWithUser(array $user, array $params): void
    {
        validateCsrf();
        $nodeId = (int) ($params['id'] ?? 0);
        $body   = $this->jsonBody();

        try {
            $share = $this->shares->shareWithUser(
                $nodeId,
                (int) $user['id'],
                trim((string) ($body['identifier'] ?? '')),
            );
            jsonResponse(['share' => $share]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function removeUserShare(array $user, array $params): void
    {
        validateCsrf();
        $nodeId  = (int) ($params['id'] ?? 0);
        $shareId = (int) ($params['shareId'] ?? 0);

        try {
            $this->shares->removeUserShare($nodeId, (int) $user['id'], $shareId);
            jsonResponse(['ok' => true]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function migrateLocal(array $user): void
    {
        validateCsrf();

        try {
            $result = $this->storage->migrateLocalFiles((int) $user['id']);
            jsonResponse(['ok' => true, 'result' => $result]);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function listShares(array $user, array $params): void
    {
        $nodeId = (int) ($params['id'] ?? 0);
        try {
            jsonResponse($this->shares->listForNode($nodeId, (int) $user['id']));
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 404);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function createShareLink(array $user, array $params): void
    {
        validateCsrf();
        $nodeId = (int) ($params['id'] ?? 0);
        $body   = $this->jsonBody();

        try {
            $link = $this->shares->createLink(
                $nodeId,
                (int) $user['id'],
                trim((string) ($body['label'] ?? '')) ?: null,
                isset($body['expires_in']) ? (string) $body['expires_in'] : null,
            );
            jsonResponse(['link' => $link], 201);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function oauthCallback(array $user, string $provider): void
    {
        if (isset($_GET['error'])) {
            $this->redirectCloudResult(false, $provider);
        }

        $code  = (string) ($_GET['code'] ?? '');
        $state = (string) ($_GET['state'] ?? '');

        if ($code === '') {
            $this->redirectCloudResult(false, $provider);
        }

        try {
            $this->connections->handleOAuthCallback((int) $user['id'], $provider, $code, $state);
            $this->redirectCloudResult(true, $provider);
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $reason  = str_contains(strtolower($message), 'files.content.')
                ? 'scope'
                : 'oauth';
            $this->redirectCloudResult(
                false,
                $provider,
                $reason,
                str_contains(strtolower($message), 'dropbox') ? $message : null,
            );
        }
    }

    /**
     * @param array<string, mixed> $user
     */
    public function startOAuthRedirect(array $user, string $provider): void
    {
        try {
            $url = $this->connections->startOAuth((int) $user['id'], $provider);
            redirect($url);
        } catch (\InvalidArgumentException $e) {
            $reason = str_contains(strtolower($e->getMessage()), 'not configured') ? 'config' : 'invalid';
            $this->redirectCloudResult(false, $provider, $reason);
        } catch (\Throwable) {
            $this->redirectCloudResult(false, $provider, 'oauth');
        }
    }

    private function redirectCloudResult(
        bool $connected,
        string $provider,
        string $errorReason = 'oauth',
        ?string $errorMessage = null,
    ): never {
        $params = ['tab' => 'files'];
        if ($connected) {
            $params['cloud_connected'] = '1';
        } else {
            $params['cloud_error'] = $errorReason;
            if ($errorMessage !== null && $errorMessage !== '') {
                $params['cloud_message'] = $errorMessage;
            }
        }
        if ($provider !== '') {
            $params['cloud_provider'] = $provider;
        }

        redirect('/settings/clipboards?' . http_build_query($params));
    }

    private function streamMeta(\Dashboard\CloudStorage\StreamMeta $meta, bool $inline): never
    {
        $filename    = preg_replace('/[^\w\s\-\.\(\)\[\]]+/u', '', $meta->filename) ?: 'download';
        $disposition = $inline ? 'inline' : 'attachment';

        header('Content-Type: ' . $meta->mime);
        if ($meta->size !== null) {
            header('Content-Length: ' . (string) $meta->size);
        }
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');

        $path = $meta->readPath();
        if ($path !== null) {
            readfile($path);
            @unlink($path);
        } else {
            echo $meta->readBody();
        }
        exit;
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
