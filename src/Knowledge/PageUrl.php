<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

use Dashboard\Support\PublicUrl;

/** Dashboard link for a vault page. Absolute when a public origin is configured. */
final class PageUrl
{
    public static function forId(int $id): string
    {
        $path = '/brain?page=' . $id;
        $base = PublicUrl::base();
        return $base === '' ? $path : $base . $path;
    }
}
