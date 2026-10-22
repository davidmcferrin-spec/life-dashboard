<?php

declare(strict_types=1);

namespace Dashboard\Support;

use Dashboard\Config;

/**
 * Links a person might open off the LAN use PUBLIC_BASE_URL when it is set.
 * APP_URL stays the on-box origin so an existing Dropbox redirect keeps working.
 */
final class PublicUrl
{
    public static function base(): string
    {
        $public = self::clean(Config::maybe('PUBLIC_BASE_URL'));
        if ($public !== '') {
            return $public;
        }
        return self::clean(Config::maybe('APP_URL'));
    }

    public static function mcp(): string
    {
        return self::clean(Config::maybe('MCP_PUBLIC_URL'));
    }

    public static function issuer(): string
    {
        return self::base();
    }

    public static function origin(string $url): string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $origin = strtolower((string) $parts['scheme']) . '://' . strtolower((string) $parts['host']);
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * @return list<string>
     */
    public static function allowedOrigins(): array
    {
        $out = [];
        foreach ([self::base(), self::clean(Config::maybe('APP_URL')), self::mcp()] as $url) {
            $origin = self::origin($url);
            if ($origin !== '') {
                $out[] = $origin;
            }
        }
        return array_values(array_unique($out));
    }

    private static function clean(?string $url): string
    {
        if ($url === null) {
            return '';
        }
        return rtrim(trim($url), '/');
    }
}
