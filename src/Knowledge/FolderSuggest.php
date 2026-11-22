<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

/**
 * A filing hint for a page left at the root. A suggestion is not a move.
 * Only a unique title match is returned, so a word that appears in the body
 * of many notes does not pick a folder.
 */
final class FolderSuggest
{
    /**
     * @param list<array{id: int, name: string, path: string}> $folders
     * @return array{id: int, name: string, path: string}|null
     */
    public static function suggest(string $title, array $folders): ?array
    {
        $best = null;
        $bestScore = 0;
        $tied = false;
        foreach ($folders as $folder) {
            $name = trim($folder['name']);
            if (mb_strlen($name) < 3) {
                continue;
            }
            $score = self::containsWord($title, $name) ? 100 : 0;
            if ($score > $bestScore) {
                $best = $folder;
                $bestScore = $score;
                $tied = false;
            } elseif ($score > 0 && $score === $bestScore) {
                $tied = true;
            }
        }
        if ($best === null || $tied) {
            return null;
        }
        return [
            'id'   => (int) $best['id'],
            'name' => (string) $best['name'],
            'path' => (string) $best['path'],
        ];
    }

    private static function containsWord(string $haystack, string $word): bool
    {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/ui';
        return preg_match($pattern, $haystack) === 1;
    }
}
