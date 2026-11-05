<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

use Dashboard\Support\ValidationException;

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

    /**
     * Point links at a renamed page. Code fences and inline code stay put,
     * so a sample that shows the old title is not rewritten.
     */
    public static function rewrite(string $body, string $from, string $to): string
    {
        $fromKey = mb_strtolower(trim($from));
        $to = trim($to);
        if ($fromKey === '' || $to === '' || $fromKey === mb_strtolower($to)) {
            return $body;
        }
        return self::mapProse($body, static function (string $prose) use ($fromKey, $to): string {
            $replaced = preg_replace_callback(
                '/\[\[([^\]|#]+)(#[^\]|]+)?(\|[^\]]+)?\]\]/u',
                static function (array $match) use ($fromKey, $to): string {
                    if (mb_strtolower(trim((string) $match[1])) !== $fromKey) {
                        return $match[0];
                    }
                    return '[[' . $to . ($match[2] ?? '') . ($match[3] ?? '') . ']]';
                },
                $prose
            );
            return is_string($replaced) ? $replaced : $prose;
        });
    }

    /**
     * The title shows up as ordinary text, not only inside a wikilink.
     * Short titles are skipped so a letter does not match every page.
     */
    public static function mentionsTitle(string $body, string $title): bool
    {
        $title = trim($title);
        if (mb_strlen($title) < 3) {
            return false;
        }
        $prose = self::mapProse($body, static function (string $chunk): string {
            $stripped = preg_replace('/\[\[[^\]]+\]\]/u', ' ', $chunk);
            return is_string($stripped) ? $stripped : $chunk;
        }, true);
        $pattern = '/(?<![\p{L}\p{N}_])' . preg_quote($title, '/') . '(?![\p{L}\p{N}_])/ui';
        return preg_match($pattern, $prose) === 1;
    }

    /**
     * @return array{title: string, heading: ?string, alias: ?string}
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw ValidationException::withFields(['link' => 'Give a [[Page title]] link to resolve.']);
        }
        $inner = $raw;
        if (preg_match('/^\[\[([\s\S]+)\]\]$/u', $raw, $wrapped) === 1) {
            $inner = (string) $wrapped[1];
        }
        if (preg_match('/^([^\]|#]+)(?:#([^\]|]+))?(?:\|([^\]]+))?$/u', $inner, $match) !== 1) {
            throw ValidationException::withFields(['link' => 'A link looks like [[Page title]], [[Page title#heading]], or [[Page title|label]].']);
        }
        $title = trim((string) $match[1]);
        if ($title === '' || mb_strlen($title) > 200) {
            throw ValidationException::withFields(['link' => 'Page title in a link must be 1–200 characters.']);
        }
        $heading = isset($match[2]) ? trim((string) $match[2]) : '';
        $alias = isset($match[3]) ? trim((string) $match[3]) : '';
        return [
            'title'   => $title,
            'heading' => $heading !== '' ? $heading : null,
            'alias'   => $alias !== '' ? $alias : null,
        ];
    }

    /**
     * Run $fn on the prose and leave fenced blocks and inline code alone.
     */
    private static function mapProse(string $body, callable $fn, bool $dropCode = false): string
    {
        $length = strlen($body);
        $out = '';
        $prose = '';
        $i = 0;
        $fence = null;
        while ($i < $length) {
            if ($fence !== null) {
                $closeAt = self::lineStart($body, $i) ? self::fenceClose($body, $i, $fence) : 0;
                if ($closeAt > 0) {
                    if (!$dropCode) {
                        $out .= substr($body, $i, $closeAt);
                    }
                    $i += $closeAt;
                    $fence = null;
                    continue;
                }
                if (!$dropCode) {
                    $out .= $body[$i];
                }
                $i++;
                continue;
            }
            if (self::lineStart($body, $i)) {
                $open = self::fenceOpen($body, $i);
                if ($open > 0) {
                    $out .= $fn($prose);
                    $prose = '';
                    $mark = $body[$i];
                    $fence = $mark;
                    if (!$dropCode) {
                        $out .= substr($body, $i, $open);
                    }
                    $i += $open;
                    continue;
                }
            }
            if ($body[$i] === '`') {
                $end = $i + 1;
                while ($end < $length && $body[$end] !== '`' && $body[$end] !== "\n" && $body[$end] !== "\r") {
                    $end++;
                }
                if ($end < $length && $body[$end] === '`') {
                    $out .= $fn($prose);
                    $prose = '';
                    if (!$dropCode) {
                        $out .= substr($body, $i, $end - $i + 1);
                    }
                    $i = $end + 1;
                    continue;
                }
            }
            $prose .= $body[$i];
            $i++;
        }
        if ($fence !== null) {
            $out .= $prose;
            return $out;
        }
        return $out . $fn($prose);
    }

    private static function lineStart(string $body, int $i): bool
    {
        return $i === 0 || $body[$i - 1] === "\n" || $body[$i - 1] === "\r";
    }

    private static function fenceOpen(string $body, int $i): int
    {
        $mark = $body[$i];
        if ($mark !== '`' && $mark !== '~') {
            return 0;
        }
        $n = 0;
        $length = strlen($body);
        while ($i + $n < $length && $body[$i + $n] === $mark) {
            $n++;
        }
        return $n >= 3 ? self::restOfLine($body, $i) : 0;
    }

    private static function fenceClose(string $body, int $i, string $mark): int
    {
        $n = 0;
        $length = strlen($body);
        while ($i + $n < $length && $body[$i + $n] === $mark) {
            $n++;
        }
        if ($n < 3) {
            return 0;
        }
        $j = $i + $n;
        while ($j < $length && ($body[$j] === ' ' || $body[$j] === "\t")) {
            $j++;
        }
        if ($j >= $length || $body[$j] === "\n" || $body[$j] === "\r") {
            return self::restOfLine($body, $i);
        }
        return 0;
    }

    private static function restOfLine(string $body, int $i): int
    {
        $length = strlen($body);
        $j = $i;
        while ($j < $length && $body[$j] !== "\n" && $body[$j] !== "\r") {
            $j++;
        }
        if ($j < $length && $body[$j] === "\r") {
            $j++;
        }
        if ($j < $length && $body[$j] === "\n") {
            $j++;
        }
        return $j - $i;
    }
}
