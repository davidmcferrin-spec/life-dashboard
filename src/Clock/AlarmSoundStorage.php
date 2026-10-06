<?php

declare(strict_types=1);

namespace Dashboard\Clock;

final class AlarmSoundStorage
{
    public const MAX_BYTES = 2_097_152;

    /** @var array<string, string> */
    private const MIME_TO_EXT = [
        'audio/mpeg'      => 'mp3',
        'audio/mp3'       => 'mp3',
        'audio/wav'       => 'wav',
        'audio/x-wav'     => 'wav',
        'audio/wave'      => 'wav',
        'audio/ogg'       => 'ogg',
        'audio/webm'      => 'webm',
    ];

    public static function baseDirectory(): string
    {
        return STORAGE_DIR . '/uploads/alarm';
    }

    public static function sharedDirectory(): string
    {
        return self::baseDirectory() . '/shared';
    }

    public static function userDirectory(): string
    {
        return self::baseDirectory() . '/user';
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     * @return array{ext: string, tmp: string}
     */
    public static function validateUpload(array $file): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \InvalidArgumentException('Upload failed.');
        }

        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('File must be between 1 byte and 2 MB.');
        }

        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new \InvalidArgumentException('Invalid upload.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime  = $finfo->file($tmp) ?: '';
        $ext   = self::MIME_TO_EXT[$mime] ?? null;

        if ($ext === null) {
            throw new \InvalidArgumentException('Allowed types: MP3, WAV, OGG, or WebM audio.');
        }

        return ['ext' => $ext, 'tmp' => $tmp];
    }

    public static function labelFromFilename(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = preg_replace('/[^\w\s\-\.]+/u', '', $base) ?? '';
        $base = trim(preg_replace('/\s+/', ' ', $base) ?? '');

        if ($base === '') {
            return 'Alarm sound';
        }

        return mb_strlen($base) > 80 ? mb_substr($base, 0, 80) : $base;
    }

    public static function ensureDirectories(): void
    {
        foreach ([self::sharedDirectory(), self::userDirectory()] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException('Could not create alarm upload directory.');
            }
        }
    }

    public static function sharedPath(string $id, string $ext): string
    {
        return self::sharedDirectory() . '/' . $id . '.' . $ext;
    }

    public static function userPath(int $userId, string $ext): string
    {
        return self::userDirectory() . '/' . $userId . '.' . $ext;
    }

    public static function userPathForUser(int $userId): ?string
    {
        $dir = self::userDirectory();
        if (!is_dir($dir)) {
            return self::legacyUserPath($userId);
        }

        foreach (['mp3', 'wav', 'ogg', 'webm'] as $ext) {
            $path = self::userPath($userId, $ext);
            if (is_file($path)) {
                return $path;
            }
        }

        return self::legacyUserPath($userId);
    }

    private static function legacyUserPath(int $userId): ?string
    {
        $dir = self::baseDirectory();
        if (!is_dir($dir)) {
            return null;
        }

        foreach (['mp3', 'wav', 'ogg', 'webm'] as $ext) {
            $path = "{$dir}/{$userId}.{$ext}";
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    public static function hasUserSound(int $userId): bool
    {
        return self::userPathForUser($userId) !== null;
    }

    /**
     * @return array{path: string, mime: string}|null
     */
    public static function metaForPath(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'webm' => 'audio/webm',
            default => 'application/octet-stream',
        };

        return ['path' => $path, 'mime' => $mime];
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     */
    public static function storeUserUpload(int $userId, array $file): void
    {
        $validated = self::validateUpload($file);
        self::ensureDirectories();
        self::deleteUserFiles($userId);

        $dest = self::userPath($userId, $validated['ext']);
        if (!move_uploaded_file($validated['tmp'], $dest)) {
            throw new \RuntimeException('Could not save alarm sound.');
        }
    }

    /**
     * @param array<string, mixed> $file $_FILES entry
     */
    public static function storeSharedUpload(string $id, array $file): string
    {
        $validated = self::validateUpload($file);
        self::ensureDirectories();

        $dest = self::sharedPath($id, $validated['ext']);
        if (!move_uploaded_file($validated['tmp'], $dest)) {
            throw new \RuntimeException('Could not save alarm sound.');
        }

        return $validated['ext'];
    }

    public static function deleteUserFiles(int $userId): void
    {
        $legacy = self::legacyUserPath($userId);
        if ($legacy !== null && is_file($legacy)) {
            @unlink($legacy);
        }

        foreach (['mp3', 'wav', 'ogg', 'webm'] as $ext) {
            $path = self::userPath($userId, $ext);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    public static function deleteSharedFile(string $id, string $ext): void
    {
        $path = self::sharedPath($id, $ext);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function generateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isValidSharedId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/i', $id);
    }
}
