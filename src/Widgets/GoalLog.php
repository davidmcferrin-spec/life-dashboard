<?php

declare(strict_types=1);

namespace Dashboard\Widgets;

/**
 * Shared daily total for a goal widget.
 *
 * HypeMan writes water_ml as one row per local day, dedupe_key water:YYYY-MM-DD,
 * and the last value wins. The widget writes that same key so the two never
 * each add on top of a different row.
 */
final class GoalLog
{
    public const DEFAULT_INCREMENTS = '250,500';

    /**
     * @return list<float>
     */
    public static function parseIncrements(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            $raw = self::DEFAULT_INCREMENTS;
        }
        $parts = preg_split('/\s*,\s*/', $raw) ?: [];
        if ($parts === [] || count($parts) > 6) {
            throw new \DomainException('Use 1–6 positive amounts, separated by commas.');
        }
        $out = [];
        foreach ($parts as $part) {
            if (!is_numeric($part) || !is_finite((float) $part) || (float) $part <= 0) {
                throw new \DomainException('Quick-add amounts must be positive numbers, separated by commas.');
            }
            $out[] = (float) $part;
        }
        return $out;
    }

    /**
     * @param list<float> $values
     */
    public static function formatIncrements(array $values): string
    {
        $parts = [];
        foreach ($values as $n) {
            $n = (float) $n;
            if (abs($n - round($n)) < 1e-9) {
                $parts[] = (string) (int) round($n);
                continue;
            }
            $parts[] = rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
        }
        return implode(',', $parts);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $metric
     */
    public static function prefix(array $config, array $metric): string
    {
        $custom = trim((string) ($config['dedupe_prefix'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $key = (string) ($metric['key'] ?? '');
        if ($key === 'water_ml') {
            return 'water';
        }
        if ($key !== '') {
            return $key;
        }
        return 'metric' . (int) ($metric['id'] ?? 0);
    }

    public static function dedupeKey(string $prefix, string $date): string
    {
        return $prefix . ':' . $date;
    }
}
