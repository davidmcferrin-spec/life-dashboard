<?php

declare(strict_types=1);

namespace Dashboard\Api;

use Dashboard\Bookmark\BookmarkCaptureService;
use Dashboard\Bookmark\BookmarkService;
use Dashboard\DB\Database;

final class BookmarksHandler
{
    private BookmarkService $bookmarks;
    private BookmarkCaptureService $capture;

    public function __construct(Database $db)
    {
        $this->bookmarks = BookmarkService::fromDatabase($db);
        $this->capture   = BookmarkCaptureService::fromDatabase($db);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function list(array $user): void
    {
        $userId = (int) $user['id'];

        jsonResponse([
            'folders' => $this->bookmarks->tree($userId),
            'unfiled' => $this->bookmarks->unfiled($userId),
        ]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function createFolder(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $folder = $this->bookmarks->createFolder(
                (int) $user['id'],
                (string) ($body['name'] ?? ''),
                $this->optionalString($body['icon'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['folder' => $folder], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function updateFolder(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid folder id.'], 400);
        }

        $body = $this->jsonBody();

        try {
            $ok = $this->bookmarks->updateFolder(
                $id,
                (int) $user['id'],
                (string) ($body['name'] ?? ''),
                $this->optionalString($body['icon'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if (!$ok) {
            jsonResponse(['error' => 'Folder not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function deleteFolder(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0 || !$this->bookmarks->deleteFolder($id, (int) $user['id'])) {
            jsonResponse(['error' => 'Folder not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function createBookmark(array $user): void
    {
        validateCsrf();
        $body = $this->jsonBody();

        try {
            $bookmark = $this->bookmarks->createBookmark(
                (int) $user['id'],
                (string) ($body['title'] ?? ''),
                (string) ($body['url'] ?? ''),
                $this->optionalFolderId($body['folder_id'] ?? null),
                $this->optionalString($body['favicon_url'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        $fetchFavicon = !array_key_exists('fetch_favicon', $body)
            || filter_var($body['fetch_favicon'], FILTER_VALIDATE_BOOLEAN);

        $clientFallback = false;
        if ($fetchFavicon && empty($bookmark['has_favicon'])) {
            try {
                $bookmark = $this->bookmarks->fetchFavicon((int) $bookmark['id'], (int) $user['id']);
            } catch (\Throwable) {
                $clientFallback = true;
            }
            if (empty($bookmark['has_favicon'])) {
                $clientFallback = true;
            }
        }

        jsonResponse([
            'bookmark'                 => $bookmark,
            'favicon_client_fallback'  => $clientFallback,
        ], 201);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function updateBookmark(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid bookmark id.'], 400);
        }

        $body = $this->jsonBody();

        try {
            $ok = $this->bookmarks->updateBookmark(
                $id,
                (int) $user['id'],
                (string) ($body['title'] ?? ''),
                (string) ($body['url'] ?? ''),
                $this->optionalFolderId($body['folder_id'] ?? null),
                $this->optionalString($body['favicon_url'] ?? null),
            );
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        if (!$ok) {
            jsonResponse(['error' => 'Bookmark not found.'], 404);
        }

        $bookmark = $this->bookmarks->getBookmark($id, (int) $user['id']);
        jsonResponse(['bookmark' => $bookmark]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function deleteBookmark(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0 || !$this->bookmarks->deleteBookmark($id, (int) $user['id'])) {
            jsonResponse(['error' => 'Bookmark not found.'], 404);
        }

        jsonResponse(['ok' => true]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function streamFavicon(array $user, array $params): void
    {
        $id  = (int) ($params['id'] ?? 0);
        $row = $this->bookmarks->findBookmarkRow($id, (int) $user['id']);

        if ($row === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found.';
            exit;
        }

        $meta = $this->bookmarks->iconService()->streamMeta($row);
        if ($meta === null) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Not found.';
            exit;
        }

        header('Content-Type: ' . $meta['mime']);
        header('Content-Length: ' . (string) filesize($meta['path']));
        header('Cache-Control: private, max-age=86400');
        readfile($meta['path']);
        exit;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function fetchFavicon(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid bookmark id.'], 400);
        }

        try {
            $bookmark = $this->bookmarks->fetchFavicon($id, (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            jsonResponse([
                'error'            => $e->getMessage(),
                'client_fallback'  => true,
            ], 422);
        }

        jsonResponse(['bookmark' => $bookmark]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function uploadFavicon(array $user, array $params): void
    {
        validateCsrf();
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid bookmark id.'], 400);
        }

        $file = $_FILES['favicon'] ?? null;
        if (!is_array($file)) {
            jsonResponse(['error' => 'No file uploaded.'], 422);
        }

        try {
            $bookmark = $this->bookmarks->uploadFavicon($id, (int) $user['id'], $file);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['bookmark' => $bookmark]);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $params
     */
    public function faviconCandidates(array $user, array $params): void
    {
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['error' => 'Invalid bookmark id.'], 400);
        }

        try {
            $candidates = $this->bookmarks->faviconCandidates($id, (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            jsonResponse(['error' => $e->getMessage()], 422);
        }

        jsonResponse(['candidates' => $candidates]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function captureToken(array $user): void
    {
        jsonResponse([
            'token'      => $this->capture->ensureToken((int) $user['id']),
            'expires_in' => 2_592_000,
        ]);
    }

    public function captureFromPage(): void
    {
        $token = trim((string) ($_POST['capture_token'] ?? ''));
        $userId = $this->capture->validateToken($token);

        if ($userId === null) {
            $this->respondCapture(['error' => 'Invalid or expired capture token. Regenerate the bookmarklet on Bookmarks settings.'], 403);
        }

        $pageUrl = trim((string) ($_POST['page_url'] ?? ''));
        $title   = trim((string) ($_POST['title'] ?? ''));
        $file    = $_FILES['favicon'] ?? null;
        $base64  = isset($_POST['favicon_base64']) ? (string) $_POST['favicon_base64'] : null;
        $bookmarkId = isset($_POST['bookmark_id']) && $_POST['bookmark_id'] !== ''
            ? (int) $_POST['bookmark_id']
            : null;

        $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        $hasBase64 = $base64 !== null && trim($base64) !== '';

        if (!$hasFile && !$hasBase64) {
            $this->respondCapture(['error' => 'No icon file received.'], 422);
        }

        try {
            $bookmark = $this->capture->captureFromPage(
                $userId,
                $pageUrl,
                $title,
                $hasFile ? $file : null,
                $hasBase64 ? $base64 : null,
                $bookmarkId,
            );
        } catch (\InvalidArgumentException $e) {
            $this->respondCapture(['error' => $e->getMessage()], 422);
        }

        $this->respondCapture([
            'ok'       => true,
            'bookmark' => $bookmark,
            'message'  => 'Icon saved for “' . ($bookmark['title'] ?? 'bookmark') . '”.',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function respondCapture(array $payload, int $status = 200): never
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $wantsJson = str_contains($accept, 'application/json')
            || str_contains($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest');

        if ($wantsJson) {
            jsonResponse($payload, $status);
        }

        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');

        $title   = $status >= 400 ? 'Capture failed' : 'Icon saved';
        $message = (string) ($payload['message'] ?? $payload['error'] ?? 'Done.');
        $detail  = isset($payload['bookmark']['title'])
            ? 'Bookmark: ' . htmlspecialchars((string) $payload['bookmark']['title'], ENT_QUOTES, 'UTF-8')
            : '';

        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</title><style>body{font-family:system-ui,sans-serif;background:#0f1419;color:#e7ecf3;padding:2rem;line-height:1.5}'
            . 'a{color:#7eb6ff}</style></head><body><h1>'
            . htmlspecialchars($title, ENT_QUOTES, 'UTF-8')
            . '</h1><p>'
            . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
            . '</p>';

        if ($detail !== '') {
            echo '<p>' . $detail . '</p>';
        }

        echo '<p><a href="/settings/bookmarks">Back to bookmarks</a></p></body></html>';
        exit;
    }

    private function optionalString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function optionalFolderId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return (int) $value;
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
