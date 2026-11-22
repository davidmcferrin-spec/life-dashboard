<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

/**
 * Title and exact-phrase hits sort above a body that only mentions the words.
 * A former title counts as a title, just under the current one.
 */
final class PageRank
{
    /**
     * @param list<string> $formerTitles
     */
    public static function score(string $query, string $title, string $body, array $formerTitles = [], bool $lexical = true): int
    {
        $query = trim($query);
        if ($query === '') {
            return 0;
        }
        $best = 0;
        $best = max($best, self::titleScore($query, $title, 1000, 800, 560));
        foreach ($formerTitles as $former) {
            $best = max($best, self::titleScore($query, (string) $former, 900, 700, 520));
        }
        if (self::hasPhrase($body, $query)) {
            $best = max($best, 400);
        } elseif (self::hasAllWords($body, $query)) {
            $best = max($best, 160);
        }
        if ($best > 0) {
            return $best;
        }
        return $lexical ? 40 : 10;
    }

    private static function titleScore(string $query, string $title, int $exact, int $phrase, int $words): int
    {
        if (mb_strtolower(trim($title)) === mb_strtolower($query)) {
            return $exact;
        }
        if (self::hasPhrase($title, $query)) {
            return $phrase;
        }
        if (self::hasAllWords($title, $query)) {
            return $words;
        }
        return 0;
    }

    private static function hasPhrase(string $haystack, string $phrase): bool
    {
        if (trim($haystack) === '' || trim($phrase) === '') {
            return false;
        }
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . '(?![\p{L}\p{N}])/ui';
        return preg_match($pattern, $haystack) === 1;
    }

    private static function hasAllWords(string $haystack, string $query): bool
    {
        $words = preg_split('/\s+/u', trim($query)) ?: [];
        $words = array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
        if ($words === [] || count($words) < 2) {
            return false;
        }
        foreach ($words as $word) {
            if (!self::hasPhrase($haystack, $word)) {
                return false;
            }
        }
        return true;
    }
}
