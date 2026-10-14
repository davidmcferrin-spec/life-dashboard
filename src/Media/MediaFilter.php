<?php

declare(strict_types=1);

namespace Dashboard\Media;

/**
 * Include keeps a card when any word appears. Exclude drops a card when any word appears.
 * An empty include list keeps every card that exclude allows.
 */
final class MediaFilter
{
    public static function allows(
        string $title,
        string $body,
        string $url,
        string $include,
        string $exclude,
        bool $hideShorts,
    ): bool {
        if ($hideShorts && (str_contains($url, '/shorts/') || str_contains(mb_strtolower($title), '#shorts'))) {
            return false;
        }
        $hay = mb_strtolower($title . ' ' . $body);
        $want = self::words($include);
        if ($want !== []) {
            $hit = false;
            foreach ($want as $word) {
                if (str_contains($hay, $word)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                return false;
            }
        }
        foreach (self::words($exclude) as $word) {
            if (str_contains($hay, $word)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private static function words(string $text): array
    {
        $parts = preg_split('/[\s,]+/u', mb_strtolower(trim($text))) ?: [];
        $out = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) >= 2) {
                $out[] = $part;
            }
        }
        return $out;
    }
}
