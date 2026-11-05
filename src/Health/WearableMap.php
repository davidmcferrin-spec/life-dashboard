<?php

declare(strict_types=1);

namespace Dashboard\Health;

/**
 * Maps Open Wearables summary and workout payloads into local points.
 * A null field is skipped. Nothing here fills a missing vital with zero.
 */
final class WearableMap
{
    /**
     * @param list<mixed> $sleep
     * @param list<mixed> $activity
     * @param list<mixed> $workouts
     * @return array{points: list<array<string, mixed>>, workouts: list<array<string, mixed>>}
     */
    public static function bundle(array $sleep, array $activity, array $workouts, string $ingestedAt): array
    {
        $points = [];
        foreach ($sleep as $row) {
            if (!is_array($row)) {
                continue;
            }
            array_push($points, ...self::sleep($row, $ingestedAt));
        }
        foreach ($activity as $row) {
            if (!is_array($row)) {
                continue;
            }
            array_push($points, ...self::activity($row, $ingestedAt));
        }
        $events = [];
        foreach ($workouts as $row) {
            if (!is_array($row)) {
                continue;
            }
            $event = self::workout($row, $ingestedAt);
            if ($event !== null) {
                $events[] = $event;
            }
        }

        return ['points' => $points, 'workouts' => $events];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public static function sleep(array $row, string $ingestedAt): array
    {
        $day = self::day($row['date'] ?? null);
        if ($day === null) {
            return [];
        }
        $meta = self::source(is_array($row['source'] ?? null) ? $row['source'] : []);
        $out = [];
        if (is_numeric($row['duration_minutes'] ?? null)) {
            $out[] = self::point('sleep', $day, (float) $row['duration_minutes'], 'min', $meta, $ingestedAt, $row['end_time'] ?? null, [
                'stages' => self::stages($row['stages'] ?? null),
                'efficiency_percent' => is_numeric($row['efficiency_percent'] ?? null) ? (float) $row['efficiency_percent'] : null,
            ]);
        }
        $hrv = $row['avg_hrv_rmssd_ms'] ?? null;
        $kind = 'rmssd';
        if (!is_numeric($hrv) && is_numeric($row['avg_hrv_sdnn_ms'] ?? null)) {
            $hrv = $row['avg_hrv_sdnn_ms'];
            $kind = 'sdnn';
        }
        if (is_numeric($hrv)) {
            $out[] = self::point('hrv', $day, (float) $hrv, 'ms', $meta, $ingestedAt, $row['end_time'] ?? null, [
                'hrv_kind' => $kind,
            ]);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    public static function activity(array $row, string $ingestedAt): array
    {
        $day = self::day($row['date'] ?? null);
        if ($day === null) {
            return [];
        }
        $meta = self::source(is_array($row['source'] ?? null) ? $row['source'] : []);
        $out = [];
        $steps = $row['steps'] ?? $row['step_count'] ?? null;
        if (is_numeric($steps)) {
            $out[] = self::point('steps', $day, (float) $steps, 'count', $meta, $ingestedAt, null, []);
        }
        $resting = $row['resting_heart_rate_bpm'] ?? $row['resting_hr_bpm'] ?? null;
        if (is_numeric($resting)) {
            $out[] = self::point('resting_hr', $day, (float) $resting, 'bpm', $meta, $ingestedAt, null, []);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    public static function workout(array $row, string $ingestedAt): ?array
    {
        $id = trim((string) ($row['id'] ?? ''));
        if ($id === '' || preg_match('/^[A-Za-z0-9_-]{1,80}$/', $id) !== 1) {
            return null;
        }
        $title = trim((string) ($row['name'] ?? $row['type'] ?? ''));
        if ($title === '') {
            return null;
        }
        $start = self::time($row['start_time'] ?? null);
        if ($start === null) {
            return null;
        }
        $end = self::time($row['end_time'] ?? null);
        $minutes = null;
        if (is_numeric($row['duration_seconds'] ?? null)) {
            $minutes = round(((float) $row['duration_seconds']) / 60, 2);
        } elseif ($end !== null) {
            $span = strtotime($end) - strtotime($start);
            if ($span > 0) {
                $minutes = round($span / 60, 2);
            }
        }
        if ($minutes === null || $minutes <= 0 || $minutes > 1440) {
            return null;
        }
        $meta = self::source(is_array($row['source'] ?? null) ? $row['source'] : []);

        return [
            'title'      => mb_substr($title, 0, 80),
            'started_at' => $start,
            'ended_at'   => $end,
            'minutes'    => $minutes,
            'authority'  => $meta['authority'],
            'dedupe_key' => 'ow:workout:' . $id,
            'provenance' => self::provenance($meta, $ingestedAt, $start, [
                'external_id' => $id,
                'type'        => isset($row['type']) ? mb_substr((string) $row['type'], 0, 40) : null,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array{authority: string, provider: string, source: ?string, device: ?string, device_name: ?string}
     */
    private static function source(array $source): array
    {
        $provider = trim((string) ($source['provider'] ?? ''));

        return [
            'authority'   => Authority::fromProvider($provider),
            'provider'    => $provider === '' ? 'unknown' : mb_substr($provider, 0, 40),
            'source'      => self::short($source['source'] ?? null),
            'device'      => self::short($source['device'] ?? null),
            'device_name' => self::short($source['device_name'] ?? null),
        ];
    }

    /**
     * @param array{authority: string, provider: string, source: ?string, device: ?string, device_name: ?string} $meta
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function point(
        string $metric,
        string $day,
        float $value,
        string $unit,
        array $meta,
        string $ingestedAt,
        mixed $observed,
        array $extra,
    ): array {
        return [
            'metric'     => $metric,
            'day'        => $day,
            'value'      => $value,
            'unit'       => $unit,
            'authority'  => $meta['authority'],
            'note'       => null,
            'dedupe_key' => 'ow:' . $meta['authority'] . ':' . $metric . ':' . $day,
            'provenance' => self::provenance($meta, $ingestedAt, is_string($observed) ? $observed : null, $extra),
        ];
    }

    /**
     * @param array{authority: string, provider: string, source: ?string, device: ?string, device_name: ?string} $meta
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function provenance(array $meta, string $ingestedAt, ?string $observed, array $extra): array
    {
        $out = [
            'provider'    => $meta['provider'],
            'source'      => $meta['source'],
            'device'      => $meta['device'],
            'device_name' => $meta['device_name'],
            'observed_at' => $observed,
            'ingested_at' => $ingestedAt,
        ];
        foreach ($extra as $key => $value) {
            if ($value !== null && $value !== []) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, int>|null
     */
    private static function stages(mixed $stages): ?array
    {
        if (!is_array($stages)) {
            return null;
        }
        $out = [];
        foreach (['awake_minutes', 'light_minutes', 'deep_minutes', 'rem_minutes'] as $key) {
            if (is_numeric($stages[$key] ?? null)) {
                $out[$key] = (int) $stages[$key];
            }
        }

        return $out === [] ? null : $out;
    }

    private static function day(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1) {
            return null;
        }

        return substr($value, 0, 10);
    }

    private static function time(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $stamp = strtotime($value);
        if ($stamp === false) {
            return null;
        }

        return gmdate('Y-m-d H:i:s', $stamp);
    }

    private static function short(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim($value);
        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, 80);
    }
}
