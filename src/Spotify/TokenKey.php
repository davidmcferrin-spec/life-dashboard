<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

use Dashboard\Config;
use Dashboard\DB\Database;

/**
 * Spotify token encryption key. Generated on first use and kept outside the web root.
 * A key already in the environment or saved before this page existed is copied into that file
 * so tokens sealed with it still open.
 */
final class TokenKey
{
    private static ?string $cached = null;

    private static ?string $pathOverride = null;

    public static function path(): string
    {
        if (self::$pathOverride !== null) {
            return self::$pathOverride;
        }
        return ROOT_DIR . '/storage/keys/spotify-token.key';
    }

    public static function usePath(?string $path): void
    {
        self::$pathOverride = $path;
        self::$cached = null;
    }

    public static function reset(): void
    {
        self::$cached = null;
    }

    public static function secret(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $path = self::path();
        $existing = self::read($path);
        if ($existing !== null) {
            self::$cached = $existing;
            return $existing;
        }
        $explicit = self::usable(Config::maybe('SPOTIFY_TOKEN_KEY'));
        $chosen = $explicit;
        if ($chosen === null && self::accountsExist()) {
            $chosen = self::usable(Config::maybe('APP_SECRET'));
        }
        if ($chosen === null) {
            $chosen = bin2hex(random_bytes(32));
        }
        self::writeNew($path, $chosen);
        $stored = self::read($path) ?? $chosen;
        self::$cached = $stored;
        return $stored;
    }

    private static function read(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw)) {
            return null;
        }
        $text = trim($raw);
        if ($text === '' || str_contains($text, "\n") || strlen($text) > 500) {
            return null;
        }
        return $text;
    }

    private static function writeNew(string $path, string $secret): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }
        $fh = @fopen($path, 'x');
        if ($fh === false) {
            return;
        }
        fwrite($fh, $secret . "\n");
        fclose($fh);
        @chmod($path, 0600);
    }

    private static function accountsExist(): bool
    {
        try {
            $count = Database::getInstance()->fetchScalar('SELECT COUNT(*) FROM spotify_account');
        } catch (\Throwable) {
            return false;
        }
        return (int) $count > 0;
    }

    private static function usable(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '' || str_starts_with($value, 'CHANGE_ME')) {
            return null;
        }
        return $value;
    }
}
