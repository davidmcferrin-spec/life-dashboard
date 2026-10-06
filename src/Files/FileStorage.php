<?php

declare(strict_types=1);

namespace Dashboard\Files;

use Dashboard\Config;

final class FileStorage
{
    /** @var array<string, list<string>> */
    private const MIME_TO_EXT = [
        'image/jpeg'              => ['jpg', 'jpeg'],
        'image/png'               => ['png'],
        'image/gif'               => ['gif'],
        'image/webp'              => ['webp'],
        'application/zip'         => ['zip'],
        'application/x-zip-compressed' => ['zip'],
        'application/x-msdownload' => ['exe'],
        'application/vnd.microsoft.portable-executable' => ['exe'],
        'application/x-msi'       => ['msi'],
        'application/vnd.ms-msi'  => ['msi'],
    ];

    public static function maxBytes(): int
    {
        return max(1, Config::int('FILES_MAX_BYTES', 52_428_800));
    }

    public static function maxPerUser(): int
    {
        return max(1, Config::int('FILES_MAX_PER_USER', 25));
    }

    public static function quotaBytes(): int
    {
        return max(self::maxBytes(), Config::int('FILES_QUOTA_BYTES', 524_288_000));
    }

    public static function baseDirectory(): string
    {
        return STORAGE_DIR . '/uploads/files';
    }

    public static function userDirectory(int $userId): string
    {
        return self::baseDirectory() . '/' . $userId;
    }

    public static function filePath(int $userId, string $storageId, string $ext): string
    {
        return self::userDirectory($userId) . '/' . $storageId . '.' . $ext;
    }

    public static function generateStorageId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValidStorageId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/i', $id);
    }

    public static function ensureUserDirectory(int $userId): void
    {
        $dir = self::userDirectory($userId);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create file upload directory.');
        }
    }

    public static function sanitizeOriginalName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));
        $base = preg_replace('/[^\w\s\-\.\(\)\[\]]+/u', '', $base) ?? '';
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? '');

        if ($base === '' || $base === '.' || $base === '..') {
            return 'upload';
        }

        return mb_strlen($base) > 255 ? mb_substr($base, 0, 255) : $base;
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array{ext: string, mime: string, tmp: string}
     */
    public static function validateUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException(self::uploadErrorMessage((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::maxBytes()) {
            $maxMb = (int) round(self::maxBytes() / 1_048_576);
            throw new \InvalidArgumentException("File must be between 1 byte and {$maxMb} MB.");
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $originalExt = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $finfo       = new \finfo(FILEINFO_MIME_TYPE);
        $mime        = $finfo->file($tmp) ?: '';
        $ext         = self::resolveExtension($mime, $originalExt, $tmp);

        if ($ext === null) {
            throw new \InvalidArgumentException('Allowed types: JPEG, PNG, GIF, WebP, ZIP, EXE, or MSI.');
        }

        if (!self::magicMatchesExtension($tmp, $ext)) {
            throw new \InvalidArgumentException('File content does not match its type.');
        }

        return ['ext' => $ext, 'mime' => $mime !== '' ? $mime : self::mimeForExt($ext), 'tmp' => $tmp];
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     */
    public static function storeUpload(int $userId, string $storageId, array $file): array
    {
        $validated = self::validateUpload($file);
        self::ensureUserDirectory($userId);

        $dest = self::filePath($userId, $storageId, $validated['ext']);
        if (!move_uploaded_file($validated['tmp'], $dest)) {
            throw new \RuntimeException('Could not save uploaded file.');
        }

        return $validated;
    }

    public static function deleteFile(int $userId, string $storageId, string $ext): void
    {
        $path = self::filePath($userId, $storageId, $ext);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function pathForRow(array $row): ?string
    {
        $userId    = (int) ($row['user_id'] ?? 0);
        $storageId = (string) ($row['storage_id'] ?? '');
        $ext       = (string) ($row['ext'] ?? '');

        if ($userId <= 0 || !self::isValidStorageId($storageId) || $ext === '') {
            return null;
        }

        $path = self::filePath($userId, $storageId, $ext);

        return is_file($path) ? $path : null;
    }

    /**
     * @return array{path: string, mime: string, inline: bool}|null
     */
    public static function streamMetaForRow(array $row): ?array
    {
        $path = self::pathForRow($row);
        if ($path === null) {
            return null;
        }

        $ext    = strtolower((string) ($row['ext'] ?? ''));
        $mime   = (string) ($row['mime_type'] ?? self::mimeForExt($ext));
        $inline = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);

        return ['path' => $path, 'mime' => $mime, 'inline' => $inline];
    }

    public static function mimeForExt(string $ext): string
    {
        $ext = strtolower($ext);

        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'zip'         => 'application/zip',
            'exe'         => 'application/x-msdownload',
            'msi'         => 'application/x-msi',
            default       => 'application/octet-stream',
        };
    }

    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File exceeds server upload limit.',
            UPLOAD_ERR_PARTIAL   => 'Upload incomplete — try again.',
            UPLOAD_ERR_NO_FILE   => 'No file uploaded.',
            default              => 'Upload failed.',
        };
    }

    private static function resolveExtension(string $mime, string $originalExt, string $tmp): ?string
    {
        $allowedExts = self::allowedExtensions();
        if ($originalExt !== '' && !in_array($originalExt, $allowedExts, true)) {
            return null;
        }

        if (isset(self::MIME_TO_EXT[$mime])) {
            $exts = self::MIME_TO_EXT[$mime];
            if ($originalExt !== '' && in_array($originalExt, $exts, true)) {
                return $originalExt === 'jpeg' ? 'jpg' : $originalExt;
            }

            return $exts[0] === 'jpeg' ? 'jpg' : $exts[0];
        }

        if ($mime === 'application/octet-stream' && in_array($originalExt, ['exe', 'msi'], true)) {
            return $originalExt;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function allowedExtensions(): array
    {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp', 'zip', 'exe', 'msi'];
    }

    private static function magicMatchesExtension(string $path, string $ext): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = fread($handle, 8) ?: '';
        fclose($handle);

        return match ($ext) {
            'jpg', 'jpeg' => str_starts_with($head, "\xFF\xD8\xFF"),
            'png'         => str_starts_with($head, "\x89PNG\r\n\x1a\n"),
            'gif'         => str_starts_with($head, 'GIF87a') || str_starts_with($head, 'GIF89a'),
            'webp'        => str_starts_with($head, 'RIFF') && str_contains((string) file_get_contents($path, false, null, 0, 12), 'WEBP'),
            'zip'         => str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06") || str_starts_with($head, "PK\x07\x08"),
            'exe'         => str_starts_with($head, 'MZ'),
            'msi'         => str_starts_with($head, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"),
            default       => false,
        };
    }
}
