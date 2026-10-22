<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * A path we are willing to redirect to after login. Absolute and
 * protocol-relative URLs are rejected so the next hop stays on this host.
 */
final class LocalPath
{
    public static function next(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }
        $path = str_replace(["\r", "\n", "\0"], '', $path);
        if ($path === '' || strlen($path) > 2000 || $path[0] !== '/') {
            return null;
        }
        if (isset($path[1]) && ($path[1] === '/' || $path[1] === '\\')) {
            return null;
        }
        if (str_contains($path, '\\') || str_contains($path, '://')) {
            return null;
        }
        return $path;
    }
}
