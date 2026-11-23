<?php

declare(strict_types=1);

namespace Dashboard\Handoff;

/**
 * PostgreSQL array literals. PDO returns a text[] or bigint[] column as a string.
 */
final class PgArray
{
    /**
     * @param list<string> $items
     */
    public static function text(array $items): string
    {
        if ($items === []) {
            return '{}';
        }
        $out = [];
        foreach ($items as $item) {
            $out[] = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $item) . '"';
        }

        return '{' . implode(',', $out) . '}';
    }

    /**
     * @param list<int> $items
     */
    public static function ints(array $items): string
    {
        if ($items === []) {
            return '{}';
        }

        return '{' . implode(',', array_map(static fn (int $id): string => (string) $id, $items)) . '}';
    }

    /**
     * @return list<string>
     */
    public static function readText(mixed $value): array
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $item) {
                if (is_string($item) || is_int($item)) {
                    $out[] = (string) $item;
                }
            }

            return $out;
        }
        if (!is_string($value)) {
            return [];
        }
        $inner = trim($value);
        if ($inner === '' || $inner === '{}') {
            return [];
        }
        if ($inner[0] === '{') {
            $inner = substr($inner, 1, -1);
        }
        if ($inner === '') {
            return [];
        }
        if ($inner[0] === '"') {
            preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/', $inner, $matches);
            $out = [];
            foreach ($matches[1] as $part) {
                $out[] = str_replace(['\\"', '\\\\'], ['"', '\\'], $part);
            }

            return $out;
        }

        return explode(',', $inner);
    }

    /**
     * @return list<int>
     */
    public static function readInts(mixed $value): array
    {
        $out = [];
        foreach (self::readText($value) as $item) {
            if (preg_match('/^-?\d+$/', $item) === 1) {
                $out[] = (int) $item;
            }
        }

        return $out;
    }
}
