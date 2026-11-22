<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

use Dashboard\Support\ValidationException;

/**
 * Append, prepend, or edit one markdown section without touching the rest of the page.
 * Headings inside fenced code are not sections.
 */
final class PagePatch
{
    public const MODES = ['append', 'prepend', 'replace_section', 'insert_section'];

    public static function apply(string $body, string $mode, string $text, ?string $heading = null, string $position = 'end'): string
    {
        $mode = strtolower(trim($mode));
        if (!in_array($mode, self::MODES, true)) {
            throw ValidationException::withFields(['mode' => 'mode must be append, prepend, replace_section, or insert_section.']);
        }
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        if (trim($text) === '') {
            throw ValidationException::withFields(['text' => 'text is required.']);
        }
        if ($mode === 'append') {
            return self::join($body, $text);
        }
        if ($mode === 'prepend') {
            return self::join($text, $body);
        }
        $wanted = self::cleanHeading($heading);
        $sections = self::sections($body);
        $matches = [];
        foreach ($sections as $section) {
            if (mb_strtolower($section['text']) === mb_strtolower($wanted)) {
                $matches[] = $section;
            }
        }
        if ($matches === []) {
            $names = array_map(static fn (array $section): string => $section['text'], $sections);
            $hint = $names === [] ? 'This page has no headings.' : 'Headings: ' . implode(', ', $names) . '.';
            throw ValidationException::withFields(['heading' => 'Heading "' . $wanted . '" was not found. ' . $hint]);
        }
        if (count($matches) > 1) {
            throw ValidationException::withFields(['heading' => 'Heading "' . $wanted . '" appears more than once.']);
        }
        $position = strtolower(trim($position));
        if ($position !== 'start' && $position !== 'end') {
            throw ValidationException::withFields(['position' => 'position must be start or end.']);
        }
        if ($mode === 'replace_section') {
            return self::replace($body, $matches[0], $text);
        }
        return self::insert($body, $matches[0], $text, $position);
    }

    private static function join(string $left, string $right): string
    {
        $left = rtrim($left, "\r\n");
        $right = ltrim(str_replace("\r\n", "\n", $right), "\n");
        if ($left === '') {
            return $right;
        }
        if (trim($right) === '') {
            return $left;
        }
        return $left . "\n\n" . $right;
    }

    private static function cleanHeading(?string $heading): string
    {
        $heading = trim((string) $heading);
        $heading = preg_replace('/^#{1,6}[ \t]+/u', '', $heading) ?? $heading;
        $heading = trim($heading);
        if ($heading === '') {
            throw ValidationException::withFields(['heading' => 'heading is required for a section change.']);
        }
        return $heading;
    }

    /**
     * @param array{start: int, heading_end: int, end: int, text: string} $section
     */
    private static function replace(string $body, array $section, string $text): string
    {
        $before = substr($body, 0, $section['start']);
        $heading = substr($body, $section['start'], $section['heading_end'] - $section['start']);
        $after = substr($body, $section['end']);
        $block = $heading . "\n\n" . trim($text, "\n") . "\n";
        if ($after !== '') {
            $block .= "\n";
        }
        return $before . $block . $after;
    }

    /**
     * @param array{start: int, heading_end: int, end: int, text: string} $section
     */
    private static function insert(string $body, array $section, string $text, string $position): string
    {
        $before = substr($body, 0, $section['heading_end']);
        $content = substr($body, $section['heading_end'], $section['end'] - $section['heading_end']);
        $after = substr($body, $section['end']);
        $piece = trim($text, "\n");
        if ($position === 'start') {
            $rest = ltrim($content, "\n");
            $block = "\n\n" . $piece . "\n\n" . $rest;
        } else {
            $base = rtrim($content, "\n");
            $block = $base . "\n\n" . $piece . "\n";
        }
        if ($after !== '' && !str_ends_with($block, "\n")) {
            $block .= "\n";
        }
        if ($after !== '' && $position === 'end' && !str_ends_with($block, "\n\n")) {
            $block .= "\n";
        }
        return $before . $block . $after;
    }

    /**
     * @return list<array{start: int, heading_end: int, end: int, text: string, level: int}>
     */
    private static function sections(string $body): array
    {
        $lines = preg_split("/\n/", $body);
        if ($lines === false) {
            return [];
        }
        $starts = [];
        $cursor = 0;
        foreach ($lines as $i => $line) {
            $starts[$i] = $cursor;
            $cursor += strlen($line);
            if ($i < count($lines) - 1) {
                $cursor += 1;
            }
        }
        $inFence = false;
        $fence = '';
        $headings = [];
        foreach ($lines as $i => $line) {
            $bare = rtrim($line, "\r");
            if (preg_match('/^(```+|~~~+)/', $bare, $mark) === 1) {
                $token = $mark[1][0];
                if (!$inFence) {
                    $inFence = true;
                    $fence = $token;
                } elseif ($fence === $token) {
                    $inFence = false;
                }
                continue;
            }
            if ($inFence) {
                continue;
            }
            if (preg_match('/^(#{1,6})[ \t]+(.+?)[ \t]*#*[ \t]*$/u', $bare, $match) !== 1) {
                continue;
            }
            $text = trim((string) preg_replace('/[ \t]+#+\s*$/u', '', (string) $match[2]));
            if ($text === '') {
                continue;
            }
            $headings[] = [
                'level'       => strlen($match[1]),
                'text'        => $text,
                'start'       => $starts[$i],
                'heading_end' => $starts[$i] + strlen($line),
                'end'         => strlen($body),
            ];
        }
        $count = count($headings);
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if ($headings[$j]['level'] <= $headings[$i]['level']) {
                    $headings[$i]['end'] = $headings[$j]['start'];
                    break;
                }
            }
        }
        return $headings;
    }
}
