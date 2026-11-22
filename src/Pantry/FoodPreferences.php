<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

/**
 * Likes and dislikes written under headings on the shopping-preferences page.
 * A heading that is not an explicit like or dislike list is left unread.
 */
final class FoodPreferences
{
    public const PAGE_TITLE = 'David — Shopping Preferences';

    /** @var list<string> */
    private const LIKES = ['like', 'likes', 'love', 'loves', 'preferred', 'favorite', 'favorites', 'favourite', 'favourites'];

    /** @var list<string> */
    private const DISLIKES = ['dislike', 'dislikes', 'avoid', 'avoids', "don't buy", 'dont buy', 'do not buy', 'never buy', 'hate', 'hates'];

    /**
     * @return array{likes: list<string>, dislikes: list<string>}
     */
    public static function listsFromMarkdown(string $body): array
    {
        $likes = [];
        $dislikes = [];
        $seenLike = [];
        $seenDislike = [];
        $mode = null;
        $fence = false;
        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            $trim = trim($line);
            if (str_starts_with($trim, '```')) {
                $fence = !$fence;
                continue;
            }
            if ($fence) {
                continue;
            }
            if (preg_match('/^#{1,6}\s+(.+?)\s*#*$/', $trim, $heading) === 1) {
                $mode = self::headingKind($heading[1]);
                continue;
            }
            if ($mode === null || $trim === '' || str_starts_with($trim, '|') || preg_match('/^[-*_]{3,}$/', $trim) === 1) {
                continue;
            }
            if (preg_match('/^(?:[-*+]|\d+[.)])\s+(.+)$/', $trim, $item) !== 1) {
                continue;
            }
            $text = self::cleanItem($item[1]);
            if ($text === null) {
                continue;
            }
            $key = mb_strtolower($text);
            if ($mode === 'like') {
                if (isset($seenLike[$key])) {
                    continue;
                }
                $seenLike[$key] = true;
                $likes[] = $text;
                continue;
            }
            if (isset($seenDislike[$key])) {
                continue;
            }
            $seenDislike[$key] = true;
            $dislikes[] = $text;
        }
        return ['likes' => $likes, 'dislikes' => $dislikes];
    }

    private static function headingKind(string $text): ?string
    {
        $text = strtolower(trim($text));
        $text = str_replace(['’', '‘'], "'", $text);
        $text = preg_replace('/[^a-z \']+/', '', $text) ?? '';
        $text = trim((string) preg_replace('/\s+/', ' ', $text));
        if (in_array($text, self::LIKES, true)) {
            return 'like';
        }
        if (in_array($text, self::DISLIKES, true)) {
            return 'dislike';
        }
        return null;
    }

    private static function cleanItem(string $text): ?string
    {
        $text = preg_replace('/\[\[([^\]|]+)\|([^\]]+)\]\]/u', '$2', $text) ?? $text;
        $text = preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $text) ?? $text;
        $text = preg_replace('/[*_`]+/u', '', $text) ?? $text;
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '' || str_starts_with($text, '|')) {
            return null;
        }
        if (mb_strlen($text) > 160) {
            $text = mb_substr($text, 0, 160);
        }
        return $text;
    }
}
