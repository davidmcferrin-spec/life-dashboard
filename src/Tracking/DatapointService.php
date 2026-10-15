<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * DatapointService — write and read the time series.
 *
 * Writes are validated against the metric kind (habit 0/1, scale bounds, …).
 * A dedupe_key turns a write into an upsert, so retries from Home Assistant,
 * cron jobs or AI tools never create duplicates.
 */
final class DatapointService
{
    public const SOURCES = ['manual', 'widget', 'api', 'ai', 'integration', 'import'];
    public const AGGREGATES = ['avg', 'sum', 'min', 'max', 'count', 'last'];
    public const BUCKETS = ['day', 'week', 'month'];
    public const MAX_NOTE = 2000;
    public const MAX_LIST = 1000;

    public function __construct(
        private readonly Database $db,
        private readonly MetricService $metrics,
        private readonly Clock $clock,
    ) {}

    /** @var null|\Closure(int, int, string, string): void */
    private ?\Closure $afterRecord = null;

    /**
     * Called after a non-habit write. Arguments: user id, metric id, local date, source.
     *
     * @param \Closure(int, int, string, string): void $callback
     */
    public function afterRecord(\Closure $callback): void
    {
        $this->afterRecord = $callback;
    }

    /**
     * Record one value.
     *
     * @param array{value: mixed, ts?: string|null, date?: string|null, note?: string|null,
     *              source?: string, dedupe_key?: string|null, meta?: array<string, mixed>} $input
     * @return array<string, mixed> the stored datapoint
     */
    public function record(int $userId, int $metricId, array $input): array
    {
        $metric = $this->metrics->get($userId, $metricId);
        if ($metric['archived']) {
            throw new ValidationException('Metric is archived.');
        }

        $value  = self::validateValue($metric, $input['value'] ?? null);
        $source = (string) ($input['source'] ?? 'manual');
        if (!in_array($source, self::SOURCES, true)) {
            throw ValidationException::withFields(['source' => 'Invalid source.']);
        }

        // Timestamp: explicit ts, or a calendar date (stored at local noon — or
        // "now" when the date is today, so a morning entry isn't in the future), or now.
        if (!empty($input['ts'])) {
            $ts = $this->clock->parse((string) $input['ts']);
        } elseif (!empty($input['date'])) {
            try {
                $date = Clock::normalizeDate((string) $input['date']);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withFields(['date' => $e->getMessage()]);
            }
            if ($date > $this->clock->today()) {
                throw ValidationException::withFields(['date' => 'Date cannot be in the future.']);
            }
            $ts = $date === $this->clock->today() ? $this->clock->now() : $this->clock->noonOf($date);
        } else {
            $ts = $this->clock->now();
        }
        if ($ts > $this->clock->now()->modify('+5 minutes')) {
            throw ValidationException::withFields(['ts' => 'Timestamp cannot be in the future.']);
        }
        $localDate = $this->clock->localDateOf($ts);

        $note = isset($input['note']) ? trim((string) $input['note']) : null;
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) {
            throw ValidationException::withFields(['note' => 'Note must be at most ' . self::MAX_NOTE . ' characters.']);
        }
        $note = $note === '' ? null : $note;

        $dedupe = isset($input['dedupe_key']) && $input['dedupe_key'] !== '' ? (string) $input['dedupe_key'] : null;
        if ($metric['kind'] === 'habit') {
            $dedupe = 'day:' . $localDate; // exactly one entry per habit per day
        }
        if ($dedupe !== null && (mb_strlen($dedupe) > 128 || !preg_match('/^[\x21-\x7e]+$/', $dedupe))) {
            throw ValidationException::withFields(['dedupe_key' => 'dedupe_key must be 1–128 printable ASCII characters.']);
        }

        $meta = $input['meta'] ?? [];
        if (!is_array($meta)) {
            throw ValidationException::withFields(['meta' => 'meta must be an object.']);
        }
        $metaJson = json_encode($meta === [] ? new \stdClass() : $meta, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (strlen($metaJson) > 4096) {
            throw ValidationException::withFields(['meta' => 'meta must be at most 4 KB.']);
        }

        $id = $this->db->fetchScalar(
            "INSERT INTO datapoints (metric_id, user_id, ts, local_date, value, note, source, dedupe_key, meta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb)
             ON CONFLICT (metric_id, dedupe_key) DO UPDATE
                SET ts = EXCLUDED.ts, local_date = EXCLUDED.local_date, value = EXCLUDED.value,
                    note = EXCLUDED.note, source = EXCLUDED.source, meta = EXCLUDED.meta,
                    updated_at = (now() AT TIME ZONE 'UTC')
             RETURNING id",
            [$metricId, $userId, $this->clock->utcString($ts), $localDate, $value, $note, $source, $dedupe, $metaJson]
        );

        $stored = $this->get($userId, (int) $id);
        if ($metric['kind'] !== 'habit' && $this->afterRecord !== null) {
            ($this->afterRecord)($userId, $metricId, $localDate, $source);
        }
        return $stored;
    }

    /**
     * @return array<string, mixed>
     */
    public function get(int $userId, int $datapointId): array
    {
        $row = $this->db->fetchOne('SELECT * FROM datapoints WHERE id = ? AND user_id = ?', [$datapointId, $userId]);
        if ($row === null) {
            throw new NotFoundException('Datapoint not found.');
        }
        return self::format($row);
    }

    public function delete(int $userId, int $datapointId): void
    {
        $n = $this->db->execute('DELETE FROM datapoints WHERE id = ? AND user_id = ?', [$datapointId, $userId]);
        if ($n === 0) {
            throw new NotFoundException('Datapoint not found.');
        }
    }

    /** Delete a habit's entry for one local day (used by toggle). Returns rows removed. */
    public function deleteForDay(int $userId, int $metricId, string $date): int
    {
        return $this->db->execute(
            'DELETE FROM datapoints WHERE user_id = ? AND metric_id = ? AND local_date = ?',
            [$userId, $metricId, Clock::normalizeDate($date)]
        );
    }

    /**
     * Raw points, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $userId, int $metricId, ?string $fromDate = null, ?string $toDate = null, int $limit = 100): array
    {
        $this->metrics->get($userId, $metricId);
        [$where, $params] = $this->rangeClause($userId, $metricId, $fromDate, $toDate);
        $limit = max(1, min(self::MAX_LIST, $limit));

        return array_map(
            [self::class, 'format'],
            $this->db->fetchAll("SELECT * FROM datapoints WHERE {$where} ORDER BY ts DESC, id DESC LIMIT {$limit}", $params)
        );
    }

    /**
     * Bucketed series, oldest first: [{bucket: 'YYYY-MM-DD', value: float, count: int}].
     * Buckets are local calendar periods (week starts Monday).
     *
     * @return list<array{bucket: string, value: float, count: int}>
     */
    public function series(int $userId, int $metricId, string $bucket, string $agg, string $fromDate, string $toDate): array
    {
        if (!in_array($bucket, self::BUCKETS, true)) {
            throw ValidationException::withFields(['bucket' => 'bucket must be day, week or month.']);
        }
        if (!in_array($agg, self::AGGREGATES, true)) {
            throw ValidationException::withFields(['agg' => 'agg must be one of ' . implode(', ', self::AGGREGATES) . '.']);
        }
        $this->metrics->get($userId, $metricId);
        [$where, $params] = $this->rangeClause($userId, $metricId, $fromDate, $toDate);

        // $bucket and $agg are whitelisted above.
        $valueExpr = match ($agg) {
            'avg'   => 'AVG(value)',
            'sum'   => 'SUM(value)',
            'min'   => 'MIN(value)',
            'max'   => 'MAX(value)',
            'count' => 'COUNT(*)',
            'last'  => '(ARRAY_AGG(value ORDER BY ts DESC, id DESC))[1]',
        };
        $rows = $this->db->fetchAll(
            "SELECT to_char(date_trunc('{$bucket}', local_date::timestamp), 'YYYY-MM-DD') AS bucket,
                    {$valueExpr}::double precision AS value, COUNT(*) AS n
             FROM datapoints WHERE {$where}
             GROUP BY 1 ORDER BY 1",
            $params
        );

        return array_map(static fn (array $r): array => [
            'bucket' => (string) $r['bucket'],
            'value'  => round((float) $r['value'], 6),
            'count'  => (int) $r['n'],
        ], $rows);
    }

    /**
     * Single aggregate over a date range (null when there is no data).
     */
    public function aggregate(int $userId, int $metricId, string $agg, ?string $fromDate, ?string $toDate): ?float
    {
        if (!in_array($agg, self::AGGREGATES, true)) {
            throw ValidationException::withFields(['agg' => 'Invalid aggregate.']);
        }
        $this->metrics->get($userId, $metricId);
        [$where, $params] = $this->rangeClause($userId, $metricId, $fromDate, $toDate);
        $valueExpr = match ($agg) {
            'avg'   => 'AVG(value)',
            'sum'   => 'SUM(value)',
            'min'   => 'MIN(value)',
            'max'   => 'MAX(value)',
            'count' => 'COUNT(*)',
            'last'  => '(ARRAY_AGG(value ORDER BY ts DESC, id DESC))[1]',
        };
        $v = $this->db->fetchScalar("SELECT {$valueExpr}::double precision FROM datapoints WHERE {$where}", $params);
        if ($agg === 'count') {
            return (float) ($v ?? 0);
        }
        return $v === null ? null : (float) $v;
    }

    /**
     * The value stored on one dedupe key, or null when that key has no row.
     * Goal logging reads this before writing the same key, so a second writer
     * updates the row instead of adding a parallel total.
     */
    public function valueForDedupe(int $userId, int $metricId, string $dedupeKey): ?float
    {
        $v = $this->db->fetchScalar(
            'SELECT value FROM datapoints WHERE user_id = ? AND metric_id = ? AND dedupe_key = ?',
            [$userId, $metricId, $dedupeKey]
        );
        return $v === null ? null : (float) $v;
    }

    /**
     * Local dates with an entry (habits), within a range.
     *
     * @return list<string>
     */
    public function datesWithValue(int $userId, int $metricId, string $fromDate, string $toDate): array
    {
        $rows = $this->db->fetchAll(
            'SELECT DISTINCT local_date FROM datapoints
             WHERE user_id = ? AND metric_id = ? AND local_date BETWEEN ? AND ? AND value > 0
             ORDER BY local_date',
            [$userId, $metricId, $fromDate, $toDate]
        );
        return array_map(static fn (array $r): string => (string) $r['local_date'], $rows);
    }

    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $metric
     */
    public static function validateValue(array $metric, mixed $raw): float
    {
        if ($raw === null || $raw === '' || (!is_numeric($raw) && !is_bool($raw))) {
            throw ValidationException::withFields(['value' => 'Value must be a number.']);
        }
        $v = is_bool($raw) ? ($raw ? 1.0 : 0.0) : (float) $raw;
        if (!is_finite($v)) {
            throw ValidationException::withFields(['value' => 'Value must be finite.']);
        }

        switch ($metric['kind']) {
            case 'habit':
                if ($v !== 0.0 && $v !== 1.0) {
                    throw ValidationException::withFields(['value' => 'Habit values are 0 or 1.']);
                }
                break;
            case 'scale':
                $min = (int) ($metric['config']['min'] ?? 1);
                $max = (int) ($metric['config']['max'] ?? 5);
                if ($v < $min || $v > $max || floor($v) !== $v) {
                    throw ValidationException::withFields(['value' => "Value must be a whole number {$min}–{$max}."]);
                }
                break;
            case 'duration':
                if ($v < 0 || $v > 86400 * 31) {
                    throw ValidationException::withFields(['value' => 'Duration must be 0 to 31 days in seconds.']);
                }
                break;
            default:
                if (abs($v) > 1e15) {
                    throw ValidationException::withFields(['value' => 'Value out of range.']);
                }
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function format(array $row): array
    {
        $meta = is_string($row['meta'] ?? null) ? json_decode($row['meta'], true) : ($row['meta'] ?? []);
        return [
            'id'         => (int) $row['id'],
            'metric_id'  => (int) $row['metric_id'],
            'ts'         => (string) $row['ts'],
            'date'       => (string) $row['local_date'],
            'value'      => (float) $row['value'],
            'note'       => $row['note'] !== null ? (string) $row['note'] : null,
            'source'     => (string) $row['source'],
            'dedupe_key' => $row['dedupe_key'] !== null ? (string) $row['dedupe_key'] : null,
            'meta'       => is_array($meta) ? $meta : [],
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function rangeClause(int $userId, int $metricId, ?string $fromDate, ?string $toDate): array
    {
        $where  = 'user_id = ? AND metric_id = ?';
        $params = [$userId, $metricId];
        if ($fromDate !== null && $fromDate !== '') {
            $where   .= ' AND local_date >= ?';
            $params[] = Clock::normalizeDate($fromDate);
        }
        if ($toDate !== null && $toDate !== '') {
            $where   .= ' AND local_date <= ?';
            $params[] = Clock::normalizeDate($toDate);
        }
        return [$where, $params];
    }
}
