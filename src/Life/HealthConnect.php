<?php

declare(strict_types=1);

namespace Dashboard\Life;

/**
 * Maps a Health Connect style payload onto the seeded life metrics.
 *
 * Accepted record types: Steps, SleepSession, Weight, ExerciseSession.
 * Times may be "start"/"end" or "startTime"/"endTime". Weight may be a
 * number of pounds, or {kilograms} / {pounds}.
 */
final class HealthConnect
{
    /**
     * @param list<mixed> $records
     * @return list<array{key: string, value: float, ts: ?string, dedupe_key: string, note: ?string}>
     */
    public static function entries(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $type = strtolower((string) ($record['type'] ?? $record['recordType'] ?? ''));
            $type = str_replace(['.', ' '], '', $type);
            $entry = match (true) {
                str_contains($type, 'step')     => self::steps($record),
                str_contains($type, 'sleep')    => self::sleep($record),
                str_contains($type, 'weight')   => self::weight($record),
                str_contains($type, 'exercise') => self::exercise($record),
                default                         => null,
            };
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $record
     * @return array{key: string, value: float, ts: ?string, dedupe_key: string, note: ?string}|null
     */
    private static function steps(array $record): ?array
    {
        $count = $record['count'] ?? $record['steps'] ?? null;
        if (!is_numeric($count)) {
            return null;
        }
        $ts = self::time($record, 'end') ?? self::time($record, 'start');
        $day = $ts !== null ? substr($ts, 0, 10) : 'day';
        return [
            'key'        => 'steps',
            'value'      => (float) $count,
            'ts'         => $ts,
            'dedupe_key' => 'hc:steps:' . $day,
            'note'       => null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{key: string, value: float, ts: ?string, dedupe_key: string, note: ?string}|null
     */
    private static function sleep(array $record): ?array
    {
        $start = self::time($record, 'start');
        $end   = self::time($record, 'end');
        if ($start === null || $end === null) {
            return null;
        }
        $seconds = strtotime($end) - strtotime($start);
        if ($seconds <= 0 || $seconds > 86400 * 2) {
            return null;
        }
        return [
            'key'        => 'sleep_hours',
            'value'      => round($seconds / 3600, 2),
            'ts'         => $end,
            'dedupe_key' => 'hc:sleep:' . substr($end, 0, 10),
            'note'       => null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{key: string, value: float, ts: ?string, dedupe_key: string, note: ?string}|null
     */
    private static function weight(array $record): ?array
    {
        $pounds = null;
        $weight = $record['weight'] ?? $record['pounds'] ?? $record['kilograms'] ?? null;
        if (is_array($weight)) {
            if (isset($weight['pounds']) && is_numeric($weight['pounds'])) {
                $pounds = (float) $weight['pounds'];
            } elseif (isset($weight['kilograms']) && is_numeric($weight['kilograms'])) {
                $pounds = (float) $weight['kilograms'] * 2.2046226218;
            }
        } elseif (is_numeric($weight)) {
            $pounds = isset($record['kilograms']) ? (float) $weight * 2.2046226218 : (float) $weight;
        }
        if (isset($record['kilograms']) && is_numeric($record['kilograms']) && !isset($record['pounds'])) {
            $pounds = (float) $record['kilograms'] * 2.2046226218;
        }
        if ($pounds === null || $pounds <= 0 || $pounds > 1500) {
            return null;
        }
        $ts = self::time($record, 'time') ?? self::time($record, 'start') ?? self::time($record, 'end');
        $day = $ts !== null ? substr($ts, 0, 10) : 'day';
        return [
            'key'        => 'weight',
            'value'      => round($pounds, 2),
            'ts'         => $ts,
            'dedupe_key' => 'hc:weight:' . $day,
            'note'       => null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     * @return array{key: string, value: float, ts: ?string, dedupe_key: string, note: ?string}|null
     */
    private static function exercise(array $record): ?array
    {
        $seconds = null;
        if (isset($record['seconds']) && is_numeric($record['seconds'])) {
            $seconds = (float) $record['seconds'];
        } else {
            $start = self::time($record, 'start');
            $end   = self::time($record, 'end');
            if ($start !== null && $end !== null) {
                $seconds = (float) (strtotime($end) - strtotime($start));
            }
        }
        if ($seconds === null || $seconds <= 0 || $seconds > 86400) {
            return null;
        }
        $ts = self::time($record, 'end') ?? self::time($record, 'start');
        $day = $ts !== null ? substr($ts, 0, 10) : 'day';
        $title = isset($record['title']) ? mb_substr(trim((string) $record['title']), 0, 80) : null;
        return [
            'key'        => 'exercise_min',
            'value'      => $seconds,
            'ts'         => $ts,
            'dedupe_key' => 'hc:exercise:' . $day,
            'note'       => $title !== '' ? $title : null,
        ];
    }

    /**
     * @param array<string, mixed> $record
     */
    private static function time(array $record, string $which): ?string
    {
        $keys = match ($which) {
            'start' => ['start', 'startTime', 'start_time'],
            'end'   => ['end', 'endTime', 'end_time'],
            default => ['time', 'timestamp'],
        };
        foreach ($keys as $key) {
            if (!empty($record[$key]) && is_string($record[$key])) {
                return $record[$key];
            }
        }
        return null;
    }
}
