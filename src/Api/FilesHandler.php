<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Files\FileService;
use Dashboard\Files\FileShareService;
use Dashboard\DB\Database;

final class FilesHandler
{
    private FileService $files;
    private FileShareService $shares;

    public function __construct(Database $db)
    {
        $this->files  = new FileService($db);
        $this->shares = new FileShareService($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        $payload = $this->files->listForOwner((int) $user['id']);
        jsonResponse($payload);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function listSharedWithMe(array $user): void
    {
        jsonResponse([
            'files' => $this->files->listSharedWithUser((int) $user['id']),
        ]);
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

        try {
            $record = $this->files->upload((int) $user['id'], $file);
            jsonResponse(['file' => $record], 201);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function download(array $user, array $params): void
    {
        $fileId = (int) ($params['id'] ?? 0);
        if ($fileId <= 0) {
            jsonResponse(['error' => 'Invalid file id.'], 400);
        }

        $meta = $this->files->streamMeta($fileId, (int) $user['id']);
        if ($meta === null) {
            jsonResponse(['error' => 'File not found.'], 404);
        }

        $this->streamFile($meta);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function delete(array $user, array $params): void
    {
        validateCsrf();

        $fileId = (int) ($params['id'] ?? 0);
        if ($fileId <= 0) {
            jsonResponse(['error' => 'Invalid file id.'], 400);
        }

        if (!$this->files->delete($fileId, (int) $user['id'])) {
            jsonResponse(['error' => 'File not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function listShares(array $user, array $params): void
    {
        $fileId = (int) ($params['id'] ?? 0);
        if ($fileId <= 0) {
            jsonResponse(['error' => 'Invalid file id.'], 400);
        }

        try {
            $shares = $this->shares->listSharesForOwner($fileId, (int) $user['id']);
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

        $fileId = (int) ($params['id'] ?? 0);
        $body   = $this->jsonBody();

        if ($fileId <= 0) {
            jsonResponse(['error' => 'Invalid file id.'], 400);
        }

        try {
            $link = $this->shares->createLink(
                $fileId,
                (int) $user['id'],
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

        $fileId = (int) ($params['id'] ?? 0);
        $linkId = (int) ($params['linkId'] ?? 0);

        if (!$this->shares->revokeLink($fileId, (int) $user['id'], $linkId)) {
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

        $fileId = (int) ($params['id'] ?? 0);
        $body   = $this->jsonBody();

        if ($fileId <= 0) {
            jsonResponse(['error' => 'Invalid file id.'], 400);
        }

        try {
            $share = $this->shares->shareWithUser(
                $fileId,
                (int) $user['id'],
                trim((string) ($body['identifier'] ?? '')),
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

        $fileId  = (int) ($params['id'] ?? 0);
        $shareId = (int) ($params['shareId'] ?? 0);

        if (!$this->shares->removeUserShare($fileId, (int) $user['id'], $shareId)) {
            jsonResponse(['error' => 'Share not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array{path: string, mime: string, inline: bool, filename: string} $meta
     */
    private function streamFile(array $meta): never
    {
        $filename = $this->safeFilename($meta['filename']);
        $disposition = ($meta['inline'] ?? false) ? 'inline' : 'attachment';

        header('Content-Type: ' . $meta['mime']);
        header('Content-Length: ' . (string) filesize($meta['path']));
        header('Content-Disposition: ' . $disposition . '; filename="' . $filename . '"');
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($meta['path']);
        exit;
    }

    private function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w\s\-\.\(\)\[\]]+/u', '', $name) ?? 'download';
        $name = trim($name);

        return $name !== '' ? $name : 'download';
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
