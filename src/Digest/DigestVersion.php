<?php

declare(strict_types=1);

namespace Dashboard\Digest;

use Dashboard\Support\ValidationException;

/**
 * A dotted version from a changelog title. Parentheses win, otherwise the
 * last dotted number. The sort key is zero-padded so 1.10 follows 1.7.
 * services/lifedash/digest_parse.py packs the same way.
 */
final class DigestVersion
{
    /**
     * @return array{version_text: string, version_sort: string}|null
     */
    public static function parse(string $title): ?array
    {
        $title = trim((string) preg_replace('/\s+/', ' ', $title));
        if ($title === '') {
            return null;
        }
        if (preg_match('/^[vV]?(\d{1,4}(?:\.\d{1,4}){1,3})$/', $title, $exact) === 1) {
            return self::pack($exact[1]);
        }
        if (preg_match('/\(([vV]?\d{1,4}(?:\.\d{1,4}){1,3})\)/', $title, $paren) === 1) {
            return self::pack(ltrim($paren[1], 'vV'));
        }
        if (preg_match_all('/\b[vV]?(\d{1,4}(?:\.\d{1,4}){1,3})\b/', $title, $all) < 1 || $all[1] === []) {
            return null;
        }
        return self::pack($all[1][count($all[1]) - 1]);
    }

    /**
     * @return array{version_text: string, version_sort: string}
     */
    public static function require(string $value, string $field): array
    {
        $parsed = self::parse($value);
        if ($parsed === null) {
            throw ValidationException::withFields([$field => 'That version was not recognized.']);
        }
        return $parsed;
    }

    /**
     * @return array{version_text: string, version_sort: string}|null
     */
    private static function pack(string $raw): ?array
    {
        $raw = ltrim($raw, 'vV');
        $parts = [];
        foreach (explode('.', $raw) as $piece) {
            if ($piece === '' || preg_match('/^\d+$/', $piece) !== 1) {
                return null;
            }
            $number = (int) $piece;
            if ($number > 99999) {
                return null;
            }
            $parts[] = $number;
        }
        if (count($parts) < 2 || count($parts) > 4) {
            return null;
        }
        $padded = array_pad($parts, 4, 0);
        $sort = implode('.', array_map(static fn (int $n): string => sprintf('%05d', $n), $padded));
        return ['version_text' => $raw, 'version_sort' => $sort];
    }
}
