<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * Browser POSTs name their page in Origin. The request host is always allowed,
 * plus the configured public and LAN origins. A missing Origin is left to the
 * CSRF token (some clients do not send one).
 */
final class RequestOrigin
{
    /**
     * @param list<string> $allowed
     */
    public static function permitted(string $origin, array $allowed, string $host, bool $https): bool
    {
        $origin = rtrim(strtolower(trim($origin)), '/');
        if ($origin === '' || $origin === 'null') {
            return true;
        }
        $host = strtolower(trim($host));
        if ($host !== '') {
            $scheme = $https ? 'https' : 'http';
            if ($origin === $scheme . '://' . $host) {
                return true;
            }
        }
        foreach ($allowed as $item) {
            if ($item !== '' && $origin === rtrim(strtolower($item), '/')) {
                return true;
            }
        }
        return false;
    }

    public static function rejectMessage(): ?string
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if (!is_string($origin) || trim($origin) === '' || strtolower(trim($origin)) === 'null') {
            return null;
        }
        $host = $_SERVER['HTTP_HOST'] ?? '';
        if (!is_string($host)) {
            $host = '';
        }
        if (self::permitted($origin, PublicUrl::allowedOrigins(), $host, RequestScheme::current())) {
            return null;
        }
        return 'This request came from a different site.';
    }
}
