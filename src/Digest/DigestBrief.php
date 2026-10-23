<?php

declare(strict_types=1);

namespace Dashboard\Digest;

/** One or two sentences from a parsed note, for rows the model has not filled yet. */
final class DigestBrief
{
    public static function excerpt(string $text): string
    {
        $cleaned = trim((string) preg_replace('/\s+/u', ' ', self::plain($text)));
        $cleaned = (string) preg_replace('/^[\[\]()•*\-–—]+\s*/u', '', $cleaned);
        if ($cleaned === '') {
            return '';
        }
        preg_match_all('/.+?(?:[.!?](?=\s|$)|$)/u', $cleaned, $matches);
        $sentences = [];
        foreach ($matches[0] as $part) {
            $bit = trim((string) $part);
            if ($bit !== '') {
                $sentences[] = $bit;
            }
        }
        if ($sentences === []) {
            return self::clip($cleaned, 220);
        }
        $chosen = $sentences[0];
        if (count($sentences) > 1 && mb_strlen($chosen) < 140) {
            $chosen .= ' ' . $sentences[1];
        }

        return self::clip($chosen, 320);
    }

    private static function plain(string $text): string
    {
        return trim((string) preg_replace('/<[^>]+>/', ' ', $text));
    }

    private static function clip(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        $cut = mb_substr($text, 0, $limit);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 40) {
            $cut = mb_substr($cut, 0, $space);
        }
        $cut = rtrim($cut, ".,;:");

        return rtrim($cut) . '.';
    }
}
