<?php

declare(strict_types=1);

namespace Dashboard;

/**
 * Config — loads .env from project root, provides typed accessors.
 *
 * Usage:
 *   Config::load(__DIR__ . '/../.env');
 *   $key = Config::get('OWM_API_KEY');
 *   $debug = Config::bool('APP_DEBUG');
 */
class Config
{
    /** @var array<string, string> */
    private static array $values = [];

    /** @var array<string, string|null> Environment values kept so an overlay can be removed. */
    private static array $beforeOverlay = [];

    private static bool $loaded = false;

    /**
     * Load a .env file. Call once at bootstrap.
     * Silently skips lines that are blank or start with #.
     */
    public static function load(string $envPath): void
    {
        if (self::$loaded) {
            return;
        }

        if (!file_exists($envPath)) {
            throw new \RuntimeException(
                ".env file not found at {$envPath}. Copy .env.example to .env and fill in values."
            );
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and blank lines
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Split on first '=' only
            $pos = strpos($line, '=');
            if ($pos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));

            // Strip inline comments (value part before ' #')
            if (($commentPos = strpos($value, ' #')) !== false) {
                $value = trim(substr($value, 0, $commentPos));
            }

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;

            // Also push into $_ENV / getenv() for compatibility
            if (!isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("{$key}={$value}");
            }
        }

        self::$loaded = true;
    }

    /**
     * Get a string value. Throws if key is required and missing.
     */
    public static function get(string $key, ?string $default = null): string
    {
        $value = self::$values[$key] ?? $_ENV[$key] ?? getenv($key) ?: null;

        if ($value === null) {
            if ($default !== null) {
                return $default;
            }
            throw new \RuntimeException("Required config key '{$key}' is not set in .env");
        }

        return (string) $value;
    }

    /**
     * Get a value that may be empty/missing — returns null instead of throwing.
     */
    public static function maybe(string $key): ?string
    {
        $value = self::$values[$key] ?? $_ENV[$key] ?? getenv($key) ?: null;
        return ($value !== null && $value !== '') ? (string) $value : null;
    }

    /**
     * The environment-file value, ignoring a later setup overlay.
     */
    public static function envValue(string $key): ?string
    {
        if (array_key_exists($key, self::$beforeOverlay)) {
            $value = self::$beforeOverlay[$key];
            return ($value !== null && $value !== '') ? $value : null;
        }
        return self::maybe($key);
    }

    /**
     * Replace one key for this process. Null clears the overlay and restores the environment value.
     */
    public static function overlay(string $key, ?string $value): void
    {
        if (!array_key_exists($key, self::$beforeOverlay)) {
            self::$beforeOverlay[$key] = array_key_exists($key, self::$values) ? self::$values[$key] : null;
        }
        if ($value === null || $value === '') {
            $original = self::$beforeOverlay[$key];
            if ($original === null || $original === '') {
                unset(self::$values[$key], $_ENV[$key]);
                putenv($key);
            } else {
                self::$values[$key] = $original;
                $_ENV[$key] = $original;
                putenv($key . '=' . $original);
            }
            return;
        }
        self::$values[$key] = $value;
        $_ENV[$key] = $value;
        putenv($key . '=' . $value);
    }

    /**
     * Boolean accessor — treats '1', 'true', 'yes', 'on' as true (case-insensitive).
     */
    public static function bool(string $key, bool $default = false): bool
    {
        try {
            $value = strtolower(self::get($key));
        } catch (\RuntimeException) {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Integer accessor.
     */
    public static function int(string $key, int $default = 0): int
    {
        try {
            return (int) self::get($key);
        } catch (\RuntimeException) {
            return $default;
        }
    }

    /**
     * Validate that required keys are present. Call after load() in bootstrap.
     *
     * @param string[] $required
     */
    public static function validate(array $required): void
    {
        $missing = [];

        foreach ($required as $key) {
            $value = self::$values[$key] ?? $_ENV[$key] ?? getenv($key) ?: null;
            if ($value === null || $value === '' || $value === 'CHANGE_ME_GENERATE_WITH_OPENSSL') {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            throw new \RuntimeException(
                'Missing required .env keys: ' . implode(', ', $missing)
            );
        }
    }

    /**
     * Dump all loaded keys (names only — not values) for debug output.
     *
     * @return string[]
     */
    public static function loadedKeys(): array
    {
        return array_keys(self::$values);
    }
}
