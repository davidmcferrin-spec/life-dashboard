<?php

declare(strict_types=1);

namespace Dashboard\Bookmark;

use Dashboard\Config;

final class BookmarkIconStorage
{
    public const MAX_BYTES = 1_048_576;

    /** @var list<string> */
    private const ALLOWED_EXT = ['ico', 'png', 'gif', 'webp', 'jpg', 'jpeg'];

    public static function maxBytes(): int
    {
        return max(1024, Config::int('BOOKMARK_FAVICON_MAX_BYTES', self::MAX_BYTES));
    }

    public static function baseDirectory(): string
    {
        return STORAGE_DIR . '/uploads/bookmark-icons';
    }

    public static function userDirectory(int $userId): string
    {
        return self::baseDirectory() . '/' . $userId;
    }

    public static function filePath(int $userId, int $bookmarkId, string $ext): string
    {
        return self::userDirectory($userId) . '/' . $bookmarkId . '.' . strtolower($ext);
    }

    public static function ensureUserDirectory(int $userId): void
    {
        $dir = self::userDirectory($userId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create bookmark icon directory.');
        }
    }

    /**
     * @return array{ext: string, mime: string}
     */
    public static function saveBytes(int $userId, int $bookmarkId, string $bytes, ?string $contentType = null): array
    {
        $max = self::maxBytes();
        if ($bytes === '' || strlen($bytes) > $max) {
            throw new \InvalidArgumentException('Favicon must be between 1 byte and ' . (int) round($max / 1_048_576) . ' MB.');
        }

        $ext = self::detectExtension($bytes, $contentType);
        if ($ext === null) {
            throw new \InvalidArgumentException('Favicon must be ICO, PNG, GIF, WebP, or JPEG.');
        }

        self::ensureUserDirectory($userId);
        self::deleteAll($userId, $bookmarkId);

        $path = self::filePath($userId, $bookmarkId, $ext);
        if (file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Could not save favicon.');
        }

        return ['ext' => $ext, 'mime' => self::mimeForExt($ext)];
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array{ext: string, mime: string}
     */
    public static function storeFromUpload(int $userId, int $bookmarkId, array $file): array
    {
        $code = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($code !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(self::uploadErrorMessage($code));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $bytes = file_get_contents($tmp);
        if ($bytes === false) {
            throw new \InvalidArgumentException('Could not read uploaded file.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp) ?: null;

        return self::saveBytes($userId, $bookmarkId, $bytes, $mime !== false ? $mime : null);
    }

    public static function deleteAll(int $userId, int $bookmarkId): void
    {
        foreach (self::ALLOWED_EXT as $ext) {
            $path = self::filePath($userId, $bookmarkId, $ext);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * @param array<string, mixed> $row bookmark row with id, user_id, favicon_ext
     * @return array{path: string, mime: string}|null
     */
    public static function streamMetaForRow(array $row): ?array
    {
        $userId     = (int) ($row['user_id'] ?? 0);
        $bookmarkId = (int) ($row['id'] ?? 0);
        $ext        = strtolower((string) ($row['favicon_ext'] ?? ''));

        if ($userId <= 0 || $bookmarkId <= 0 || $ext === '' || !in_array($ext, self::ALLOWED_EXT, true)) {
            return null;
        }

        $path = self::filePath($userId, $bookmarkId, $ext);

        return is_file($path) ? ['path' => $path, 'mime' => self::mimeForExt($ext)] : null;
    }

    public static function mimeForExt(string $ext): string
    {
        return match (strtolower($ext)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'ico'         => 'image/x-icon',
            default       => 'application/octet-stream',
        };
    }

    private static function detectExtension(string $bytes, ?string $contentType): ?string
    {
        $fromMime = self::extFromContentType($contentType);
        if ($fromMime !== null && self::magicMatches($bytes, $fromMime)) {
            return $fromMime;
        }

        foreach (self::ALLOWED_EXT as $ext) {
            if (self::magicMatches($bytes, $ext)) {
                return $ext;
            }
        }

        return null;
    }

    private static function extFromContentType(?string $contentType): ?string
    {
        if ($contentType === null || $contentType === '') {
            return null;
        }

        $mime = strtolower(trim(explode(';', $contentType)[0]));

        return match ($mime) {
            'image/png'                => 'png',
            'image/gif'                => 'gif',
            'image/webp'               => 'webp',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/x-icon', 'image/vnd.microsoft.icon' => 'ico',
            default                    => null,
        };
    }

    private static function magicMatches(string $bytes, string $ext): bool
    {
        if (strlen($bytes) < 4) {
            return false;
        }

        $ext = strtolower($ext);

        return match ($ext) {
            'png' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n"),
            'gif' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a'),
            'jpg', 'jpeg' => str_starts_with($bytes, "\xFF\xD8\xFF"),
            'webp' => str_starts_with($bytes, 'RIFF') && strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP',
            'ico' => strlen($bytes) >= 6 && substr($bytes, 0, 4) === "\0\0\1\0",
            default => false,
        };
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds upload limit (max 1 MB).',
            UPLOAD_ERR_PARTIAL                        => 'Upload incomplete — try again.',
            UPLOAD_ERR_NO_FILE                        => 'No file selected.',
            default                                   => 'Upload failed.',
        };
    }
}
