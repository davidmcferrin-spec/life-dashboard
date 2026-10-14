<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

/** [[Title]], [[Title|label]], and [[Title#heading]] targets. */
final class Wikilinks
{
    /**
     * @return list<string> unique titles, original casing of the first hit, max 200 chars
     */
    public static function titles(string $body): array
    {
        if (!preg_match_all('/\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|[^\]]+)?\]\]/u', $body, $matches)) {
            return [];
        }
        $seen = [];
        $out  = [];
        foreach ($matches[1] as $raw) {
            $title = trim((string) $raw);
            if ($title === '') {
                continue;
            }
            $title = mb_substr($title, 0, 200);
            $key = mb_strtolower($title);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $title;
        }
        return $out;
    }
}
