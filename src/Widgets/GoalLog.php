<?php

declare(strict_types=1);

namespace Dashboard\Widgets;

use Dashboard\Support\ValidationException;

/**
 * Shared daily total for a goal widget.
 *
 * HypeMan writes water_ml as one row per local day, dedupe_key water:YYYY-MM-DD,
 * and movement_sessions as movement:YYYY-MM-DD. The last value wins. The widget
 * writes that same key so the two never each add on top of a different row.
 *
 * A widget holds 1–4 metrics. Quick-add settings live on config.logs, keyed by
 * metric id. A saved config that still has metric_id (and optional increments
 * / dedupe_prefix) is accepted and rewritten into that shape.
 */
final class GoalLog
{
    public const DEFAULT_INCREMENTS = '250,500';

    public const COUNT_INCREMENTS = '1';

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public static function acceptLegacy(array $config): array
    {
        $hasIncrements = array_key_exists('increments', $config);
        $hasPrefix = array_key_exists('dedupe_prefix', $config);
        if (!isset($config['metric_ids']) && isset($config['metric_id']) && $config['metric_id'] !== '' && $config['metric_id'] !== null) {
            $config['metric_ids'] = [$config['metric_id']];
        }
        unset($config['metric_id']);

        $ids = [];
        if (isset($config['metric_ids']) && is_array($config['metric_ids']) && array_is_list($config['metric_ids'])) {
            foreach ($config['metric_ids'] as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }
        if (($hasIncrements || $hasPrefix) && count($ids) !== 1) {
            throw ValidationException::withFields([
                'config.logs' => 'Put each metric\'s quick-add amounts in config.logs.',
            ]);
        }
        if (($hasIncrements || $hasPrefix) && count($ids) === 1) {
            $key = (string) $ids[0];
            $logs = is_array($config['logs'] ?? null) ? $config['logs'] : [];
            $entry = [];
            $raw = $logs[$key] ?? $logs[$ids[0]] ?? null;
            if (is_array($raw)) {
                $entry = $raw;
            }
            if ($hasIncrements && !array_key_exists('increments', $entry)) {
                $entry['increments'] = $config['increments'];
            }
            if ($hasPrefix && !array_key_exists('dedupe_prefix', $entry) && trim((string) $config['dedupe_prefix']) !== '') {
                $entry['dedupe_prefix'] = $config['dedupe_prefix'];
            }
            $logs[$key] = $entry;
            $config['logs'] = $logs;
        }
        unset($config['increments'], $config['dedupe_prefix']);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<int>
     */
    public static function metricIds(array $config): array
    {
        if (isset($config['metric_ids']) && is_array($config['metric_ids'])) {
            $ids = [];
            foreach ($config['metric_ids'] as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
            return array_values(array_unique($ids));
        }
        if (isset($config['metric_id']) && is_numeric($config['metric_id']) && (int) $config['metric_id'] > 0) {
            return [(int) $config['metric_id']];
        }
        return [];
    }

    /**
     * Saved quick-add row for one metric, including a single-goal widget that
     * still stores increments and dedupe_prefix at the top level.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    public static function logEntry(array $config, array $metric): array
    {
        $id = (int) ($metric['id'] ?? 0);
        $logs = is_array($config['logs'] ?? null) ? $config['logs'] : [];
        $entry = $logs[(string) $id] ?? $logs[$id] ?? [];
        if (!is_array($entry)) {
            $entry = [];
        }
        $ids = self::metricIds($config);
        if (count($ids) === 1 && $ids[0] === $id) {
            if (!array_key_exists('increments', $entry) && array_key_exists('increments', $config)) {
                $entry['increments'] = $config['increments'];
            }
            if (!array_key_exists('dedupe_prefix', $entry) && array_key_exists('dedupe_prefix', $config) && trim((string) $config['dedupe_prefix']) !== '') {
                $entry['dedupe_prefix'] = $config['dedupe_prefix'];
            }
        }
        return $entry;
    }

    /**
     * @param array<string, mixed> $metric
     * @param array<string, mixed> $entry
     */
    public static function mode(array $metric, array $entry = []): string
    {
        $forced = (string) ($entry['mode'] ?? '');
        if ($forced === 'amount' || $forced === 'count') {
            return $forced;
        }
        $unit = strtolower(trim((string) ($metric['unit'] ?? '')));
        if (in_array($unit, ['session', 'sessions', 'count', 'times'], true)) {
            return 'count';
        }
        $key = (string) ($metric['key'] ?? '');
        if ($key === 'movement_sessions' || str_ends_with($key, '_sessions') || str_ends_with($key, '_count')) {
            return 'count';
        }
        if ($unit !== '') {
            return 'amount';
        }
        $goal = $metric['config']['goal'] ?? null;
        if (is_numeric($goal) && (float) $goal >= 1 && (float) $goal <= 12 && abs((float) $goal - round((float) $goal)) < 1e-9) {
            return 'count';
        }
        return 'amount';
    }

    /**
     * @param array<string, mixed> $metric
     */
    public static function defaultIncrements(array $metric, string $mode): string
    {
        if ($mode === 'count') {
            return self::COUNT_INCREMENTS;
        }
        return self::DEFAULT_INCREMENTS;
    }

    /**
     * How many pips to draw for a count goal: the goal, plus extra pips through
     * 200%, capped so a row stays one line (goal 3 draws 6).
     */
    public static function pipCount(float $goal): int
    {
        $n = (int) round(max(1.0, $goal) * 2);
        return max(1, min(12, $n));
    }

    /**
     * @param array<string, mixed> $metric
     */
    public static function icon(array $metric): string
    {
        return match ((string) ($metric['key'] ?? '')) {
            'water_ml' => '💧',
            'movement_sessions' => '🏃',
            'steps' => '👟',
            'sleep_hours' => '☾',
            'exercise_min' => '⏱',
            default => '',
        };
    }

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
     * @param array<string, mixed> $entry  one logs row, or a legacy config with dedupe_prefix
     * @param array<string, mixed> $metric
     */
    public static function prefix(array $entry, array $metric): string
    {
        $custom = trim((string) ($entry['dedupe_prefix'] ?? ''));
        if ($custom !== '') {
            return $custom;
        }
        $key = (string) ($metric['key'] ?? '');
        if ($key === 'water_ml') {
            return 'water';
        }
        if ($key === 'movement_sessions') {
            return 'movement';
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

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function cleanLogRow(array $row): array
    {
        $unknown = array_diff(array_keys($row), ['increments', 'dedupe_prefix', 'mode']);
        if ($unknown !== []) {
            throw new \DomainException('Unknown quick-add keys: ' . implode(', ', $unknown) . '.');
        }
        $out = [];
        if (array_key_exists('increments', $row) && $row['increments'] !== null && $row['increments'] !== '') {
            $out['increments'] = self::formatIncrements(self::parseIncrements((string) $row['increments']));
        }
        if (array_key_exists('dedupe_prefix', $row) && trim((string) $row['dedupe_prefix']) !== '') {
            $prefix = trim((string) $row['dedupe_prefix']);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,39}$/', $prefix)) {
                throw new \DomainException('Use letters, digits, and . _ - only.');
            }
            $out['dedupe_prefix'] = $prefix;
        }
        if (array_key_exists('mode', $row) && $row['mode'] !== null && $row['mode'] !== '') {
            $mode = (string) $row['mode'];
            if ($mode !== 'amount' && $mode !== 'count') {
                throw new \DomainException('Mode must be amount or count.');
            }
            $out['mode'] = $mode;
        }
        return $out;
    }
}
