<?php

declare(strict_types=1);

namespace Dashboard\Search;

use Dashboard\Support\ValidationException;

/**
 * Turns a search request into words and a dataset list.
 * A word matches when those letters appear in the stored text.
 */
final class SearchQuery
{
    public const DATASETS = [
        'pages', 'feeds', 'metrics', 'todos', 'bookmarks', 'clipboards',
        'money', 'invest', 'widgets', 'files', 'stocks', 'cloudpad',
        'youtube', 'reddit', 'x',
    ];

    public const MAX_WORDS = 8;

    /**
     * @return list<string> lowercase words, 2–64 characters
     */
    public static function words(string $q): array
    {
        $parts = preg_split('/\s+/u', trim($q)) ?: [];
        $words = [];
        foreach ($parts as $part) {
            $word = mb_strtolower(trim((string) $part));
            if ($word === '') {
                continue;
            }
            if (mb_strlen($word) < 2) {
                throw ValidationException::withFields(['q' => 'Each word needs at least two letters.']);
            }
            $words[] = mb_substr($word, 0, 64);
        }
        if ($words === []) {
            throw ValidationException::withFields(['q' => 'Enter a word to search for.']);
        }
        if (count($words) > self::MAX_WORDS) {
            throw ValidationException::withFields(['q' => 'Search for at most ' . self::MAX_WORDS . ' words.']);
        }
        return $words;
    }

    /**
     * @param list<string>|null $include empty or null means every dataset
     * @param list<string>|null $exclude
     * @return list<string>
     */
    public static function datasets(?array $include, ?array $exclude): array
    {
        $selected = ($include === null || $include === [])
            ? self::DATASETS
            : self::names($include, 'include');
        $drop = self::names($exclude ?? [], 'exclude');
        $kept = array_values(array_filter(
            $selected,
            static fn (string $name): bool => !in_array($name, $drop, true)
        ));
        if ($kept === []) {
            throw ValidationException::withFields(['include' => 'No datasets left to search.']);
        }
        return $kept;
    }

    /**
     * @param list<string> $words
     */
    public static function snippet(string $title, string $body, array $words): string
    {
        $hay = trim($title . ' ' . $body);
        $lower = mb_strtolower($hay);
        $pos = null;
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $at = mb_strpos($lower, $word);
            if ($at !== false && ($pos === null || $at < $pos)) {
                $pos = $at;
            }
        }
        if ($pos === null) {
            return mb_substr($hay, 0, 180);
        }
        $start = max(0, $pos - 60);
        $slice = mb_substr($hay, $start, 180);
        if ($start > 0) {
            $slice = '…' . $slice;
        }
        if ($start + 180 < mb_strlen($hay)) {
            $slice .= '…';
        }
        return $slice;
    }

    /**
     * @param list<string> $names
     * @return list<string>
     */
    private static function names(array $names, string $field): array
    {
        $out = [];
        foreach ($names as $name) {
            $dataset = strtolower(trim((string) $name));
            if ($dataset === '') {
                continue;
            }
            if (!in_array($dataset, self::DATASETS, true)) {
                throw ValidationException::withFields([$field => "Unknown dataset '{$dataset}'."]);
            }
            $out[] = $dataset;
        }
        return array_values(array_unique($out));
    }
}
