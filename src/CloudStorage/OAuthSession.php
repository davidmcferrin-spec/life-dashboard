<?php

declare(strict_types=1);

namespace Dashboard\CloudStorage;

/**
 * OAuth PKCE / state values stored in PHP session during connect flow.
 */
final class OAuthSession
{
    private const KEY_PREFIX = 'cloud_oauth_';

    public static function put(string $provider, array $data): void
    {
        initAppSession();
        $_SESSION[self::KEY_PREFIX . $provider] = $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function pull(string $provider): ?array
    {
        initAppSession();
        $key = self::KEY_PREFIX . $provider;
        if (!isset($_SESSION[$key]) || !is_array($_SESSION[$key])) {
            return null;
        }
        $data = $_SESSION[$key];
        unset($_SESSION[$key]);

        return $data;
    }
}
