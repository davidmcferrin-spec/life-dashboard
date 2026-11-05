<?php

declare(strict_types=1);

namespace Dashboard\Health;

use Dashboard\Brief\BriefText;
use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/**
 * Stored vitals, readiness, and manual logs.
 * Device samples come from Open Wearables. This service does not call Samsung.
 */
final class HealthService
{
    private HealthSettings $settings;

    public function __construct(private readonly Database $db, private readonly Clock $clock, ?HealthSettings $settings = null)
    {
        $this->settings = $settings ?? new HealthSettings($db);
    }

    /**
     * @return array<string, mixed>
     */
    public function status(int $userId): array
    {
        $view = $this->settings->view();
        $sync = $this->db->fetchOne(
            'SELECT last_ok_at, last_error, last_at FROM health_sync WHERE user_id = ?',
            [$userId]
        );
        $latest = $this->latestDaily($userId);

        return [
            'open_wearables' => [
                'configured'  => $view['configured'],
                'api_url'     => $view['api_url'],
                'user_id'     => $view['user_id'],
                'secret_hint' => $view['secret_hint'],
            ],
            'sync' => [
                'last_ok_at' => $sync['last_ok_at'] ?? null,
                'last_error' => $sync['last_error'] ?? null,
                'last_at'    => $sync['last_at'] ?? null,
            ],
            'latest' => $latest,
            'providers_ready' => ['samsung', 'health_connect', 'garmin', 'whoop', 'oura'],
            'primary' => 'samsung',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readiness(int $userId, ?string $day = null): array
    {
        $day = $day === null || $day === '' ? null : $this->day($day, 'day');
        $row = $day === null ? $this->latestDaily($userId) : $this->daily($userId, $day);

        return [
            'today' => $this->clock->today(),
            'day'   => $row['day'] ?? null,
            'row'   => $row,
        ];
    }

    /**
     * @return array{sleep: list<array<string, mixed>>}
     */
    public function sleep(int $userId, string $from, string $to): array
    {
        return ['sleep' => $this->metrics($userId, 'sleep', $from, $to)['points']];
    }

    /**
     * @return array{workouts: list<array<string, mixed>>}
     */
    public function workouts(int $userId, string $from, string $to): array
    {
        [$from, $to] = $this->range($from, $to);
        $rows = $this->db->fetchAll(
            'SELECT id, title, started_at, ended_at, minutes, authority, provenance
             FROM health_workouts
             WHERE user_id = ? AND CAST(started_at AS date) >= ? AND CAST(started_at AS date) <= ?
             ORDER BY started_at DESC
             LIMIT 100',
            [$userId, $from, $to]
        );

        return ['workouts' => array_map(fn (array $row): array => $this->workout($row), $rows)];
    }

    /**
     * @return array{points: list<array<string, mixed>>}
     */
    public function metrics(int $userId, string $metric, string $from, string $to): array
    {
        if ($metric !== '' && !in_array($metric, ['sleep', 'hrv', 'resting_hr', 'steps', 'body', 'meal'], true)) {
            throw ValidationException::withFields(['metric' => 'Unknown metric.']);
        }
        [$from, $to] = $this->range($from, $to);
        $rows = $this->db->fetchAll(
            'SELECT id, metric, day, value, unit, authority, provenance, note
             FROM health_points
             WHERE user_id = ? AND day >= ? AND day <= ? AND (? = \'\' OR metric = ?)
             ORDER BY day DESC, metric
             LIMIT 200',
            [$userId, $from, $to, $metric, $metric]
        );

        return ['points' => array_map(fn (array $row): array => $this->point($row), $rows)];
    }

    /**
     * @return array<string, mixed>
     */
    public function brief(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT day, body, created_at FROM health_briefs WHERE user_id = ? ORDER BY day DESC LIMIT 1',
            [$userId]
        );
        if ($row === null) {
            return ['brief' => null];
        }

        return [
            'brief' => [
                'day'        => (string) $row['day'],
                'body'       => (string) $row['body'],
                'created_at' => (string) $row['created_at'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $userId): array
    {
        $view = $this->settings->view();
        $latest = $this->latestDaily($userId);
        $stored = $this->brief($userId)['brief'];

        return [
            'configured' => $view['configured'],
            'today'      => $this->clock->today(),
            'stored'     => $latest !== null || $stored !== null,
            'day'        => $latest['day'] ?? ($stored['day'] ?? null),
            'row'        => $latest,
            'brief'      => $stored['body'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(int $userId): array
    {
        $connection = $this->settings->connection();
        if ($connection === null) {
            return [
                'connected' => false,
                'detail'    => 'Open Wearables is not connected. Add the URL, API key, and user id in Settings.',
            ];
        }
        $end = $this->clock->today();
        $start = (new \DateTimeImmutable($end))->modify('-6 days')->format('Y-m-d');
        $pulled = (new OpenWearables($connection['base'], $connection['token']))->pull(
            $connection['user_id'],
            $start,
            $end,
            gmdate('Y-m-d H:i:s')
        );
        $this->store($userId, $pulled['points'], $pulled['workouts']);
        $error = $pulled['errors'] === [] ? null : implode(' ', $pulled['errors']);
        $this->rememberSync($userId, $error);

        return [
            'connected' => true,
            'ok'        => $error === null,
            'from'      => $start,
            'to'        => $end,
            'points'    => count($pulled['points']),
            'workouts'  => count($pulled['workouts']),
            'error'     => $error,
            'latest'    => $this->latestDaily($userId),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function logWorkout(int $userId, array $input): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 80) {
            throw ValidationException::withFields(['title' => 'Title must be 1–80 characters.']);
        }
        $minutes = $input['minutes'] ?? null;
        if (!is_numeric($minutes) || (float) $minutes <= 0 || (float) $minutes > 1440) {
            throw ValidationException::withFields(['minutes' => 'Minutes must be between 0 and 1440.']);
        }
        $day = $this->optionalDay($input['day'] ?? null);
        $started = $day . ' 12:00:00';
        $note = $this->note($input['note'] ?? null);
        $dedupe = 'manual:workout:' . hash('sha256', $day . '|' . mb_strtolower($title) . '|' . $minutes);
        $provenance = $this->manualProvenance($note);
        $this->db->execute(
            'INSERT INTO health_workouts (user_id, title, started_at, minutes, authority, provenance, dedupe_key)
             VALUES (?, ?, ?, ?, \'manual\', ?::jsonb, ?)
             ON CONFLICT (user_id, dedupe_key) DO UPDATE
             SET title = EXCLUDED.title, minutes = EXCLUDED.minutes, provenance = EXCLUDED.provenance',
            [$userId, $title, $started, round((float) $minutes, 2), json_encode($provenance, JSON_THROW_ON_ERROR), $dedupe]
        );
        $row = $this->db->fetchOne(
            'SELECT id, title, started_at, ended_at, minutes, authority, provenance FROM health_workouts WHERE user_id = ? AND dedupe_key = ?',
            [$userId, $dedupe]
        );

        return ['workout' => $this->workout($row ?? [])];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function logMeal(int $userId, array $input): array
    {
        $note = $this->note($input['note'] ?? null);
        if ($note === null) {
            throw ValidationException::withFields(['note' => 'Describe the meal.']);
        }
        $calories = $input['calories'] ?? null;
        if ($calories !== null && $calories !== '' && (!is_numeric($calories) || (float) $calories < 0 || (float) $calories > 20000)) {
            throw ValidationException::withFields(['calories' => 'Calories must be from 0 to 20000, or empty.']);
        }
        $value = ($calories === null || $calories === '') ? null : round((float) $calories, 1);
        $day = $this->optionalDay($input['day'] ?? null);
        $dedupe = 'manual:meal:' . hash('sha256', $day . '|' . mb_strtolower($note) . '|' . ($value ?? ''));
        $this->putPoint($userId, 'meal', $day, $value, $value === null ? 'note' : 'kcal', 'manual', $note, $dedupe, $this->manualProvenance(null));

        return ['meal' => $this->point($this->findPoint($userId, $dedupe))];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function logBody(int $userId, array $input): array
    {
        $weight = $input['weight_lb'] ?? null;
        if (!is_numeric($weight) || (float) $weight <= 0 || (float) $weight > 1500) {
            throw ValidationException::withFields(['weight_lb' => 'Weight must be a number of pounds.']);
        }
        $day = $this->optionalDay($input['day'] ?? null);
        $note = $this->note($input['note'] ?? null);
        $dedupe = 'manual:body:' . $day;
        $this->putPoint($userId, 'body', $day, round((float) $weight, 2), 'lb', 'manual', $note, $dedupe, $this->manualProvenance(null));
        $this->refreshBrief($userId, $day);

        return ['body' => $this->point($this->findPoint($userId, $dedupe))];
    }

    /**
     * @param list<array<string, mixed>> $points
     * @param list<array<string, mixed>> $workouts
     */
    public function store(int $userId, array $points, array $workouts): void
    {
        $this->db->transaction(function () use ($userId, $points, $workouts): void {
            $days = [];
            foreach ($points as $point) {
                $this->putPoint(
                    $userId,
                    (string) $point['metric'],
                    (string) $point['day'],
                    isset($point['value']) ? (float) $point['value'] : null,
                    (string) $point['unit'],
                    (string) $point['authority'],
                    $point['note'] ?? null,
                    (string) $point['dedupe_key'],
                    is_array($point['provenance'] ?? null) ? $point['provenance'] : []
                );
                $days[(string) $point['day']] = true;
            }
            foreach ($workouts as $workout) {
                $this->db->execute(
                    'INSERT INTO health_workouts (user_id, title, started_at, ended_at, minutes, authority, provenance, dedupe_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?)
                     ON CONFLICT (user_id, dedupe_key) DO UPDATE
                     SET title = EXCLUDED.title, started_at = EXCLUDED.started_at, ended_at = EXCLUDED.ended_at,
                         minutes = EXCLUDED.minutes, authority = EXCLUDED.authority, provenance = EXCLUDED.provenance',
                    [
                        $userId,
                        (string) $workout['title'],
                        (string) $workout['started_at'],
                        $workout['ended_at'] ?? null,
                        $workout['minutes'] ?? null,
                        (string) $workout['authority'],
                        json_encode($workout['provenance'] ?? new \stdClass(), JSON_THROW_ON_ERROR),
                        (string) $workout['dedupe_key'],
                    ]
                );
            }
            if ($days !== []) {
                $this->rebuild($userId, array_keys($days));
            }
        });
    }

    /**
     * @param list<string> $days
     */
    private function rebuild(int $userId, array $days): void
    {
        sort($days);
        $start = $days[0];
        $end = $days[count($days) - 1];
        $rows = $this->db->fetchAll(
            'SELECT metric, day, value, authority FROM health_points
             WHERE user_id = ? AND day >= ? AND day <= ? AND metric IN (\'sleep\', \'hrv\', \'resting_hr\', \'steps\')
               AND value IS NOT NULL',
            [$userId, $start, $end]
        );
        $prior = $this->db->fetchAll(
            'SELECT day, hrv_ms FROM health_daily
             WHERE user_id = ? AND hrv_ms IS NOT NULL AND day < ?
             ORDER BY day DESC LIMIT 14',
            [$userId, $start]
        );
        $prior = array_reverse($prior);
        $points = [];
        foreach ($rows as $row) {
            $points[] = [
                'metric'    => (string) $row['metric'],
                'day'       => (string) $row['day'],
                'value'     => (float) $row['value'],
                'authority' => (string) $row['authority'],
            ];
        }
        $history = [];
        foreach ($prior as $row) {
            $history[] = ['day' => (string) $row['day'], 'hrv_ms' => (float) $row['hrv_ms']];
        }
        $rolled = DailyRollup::days($points, $history);
        foreach ($days as $day) {
            $row = $rolled[$day] ?? null;
            if ($row === null) {
                continue;
            }
            $this->db->execute(
                'INSERT INTO health_daily (
                    user_id, day, sleep_minutes, hrv_ms, resting_hr, steps,
                    readiness_score, readiness_confidence,
                    sleep_authority, hrv_authority, resting_authority, steps_authority
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, day) DO UPDATE SET
                    sleep_minutes = EXCLUDED.sleep_minutes,
                    hrv_ms = EXCLUDED.hrv_ms,
                    resting_hr = EXCLUDED.resting_hr,
                    steps = EXCLUDED.steps,
                    readiness_score = EXCLUDED.readiness_score,
                    readiness_confidence = EXCLUDED.readiness_confidence,
                    sleep_authority = EXCLUDED.sleep_authority,
                    hrv_authority = EXCLUDED.hrv_authority,
                    resting_authority = EXCLUDED.resting_authority,
                    steps_authority = EXCLUDED.steps_authority,
                    updated_at = (now() AT TIME ZONE \'UTC\')',
                [
                    $userId,
                    $day,
                    $row['sleep_minutes'],
                    $row['hrv_ms'],
                    $row['resting_hr'],
                    $row['steps'],
                    $row['readiness_score'],
                    $row['readiness_confidence'],
                    $row['sleep_authority'],
                    $row['hrv_authority'],
                    $row['resting_authority'],
                    $row['steps_authority'],
                ]
            );
            $this->refreshBrief($userId, $day);
        }
    }

    private function refreshBrief(int $userId, string $day): void
    {
        $row = $this->daily($userId, $day);
        $body = BriefText::health($day, $row, $this->clock->today());
        $weight = $this->db->fetchOne(
            'SELECT value, authority FROM health_points
             WHERE user_id = ? AND day = ? AND metric = \'body\' AND value IS NOT NULL
             ORDER BY id DESC LIMIT 1',
            [$userId, $day]
        );
        if ($weight !== null) {
            $body .= ' Body weight ' . $weight['value'] . ' lb (' . $weight['authority'] . ').';
        }
        $this->db->execute(
            'INSERT INTO health_briefs (user_id, day, body) VALUES (?, ?, ?)
             ON CONFLICT (user_id, day) DO UPDATE SET body = EXCLUDED.body, created_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $day, $body]
        );
    }

    /**
     * @param array<string, mixed> $provenance
     */
    private function putPoint(
        int $userId,
        string $metric,
        string $day,
        ?float $value,
        string $unit,
        string $authority,
        ?string $note,
        string $dedupe,
        array $provenance,
    ): void {
        $this->db->execute(
            'INSERT INTO health_points (user_id, metric, day, value, unit, authority, provenance, note, dedupe_key)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?, ?)
             ON CONFLICT (user_id, dedupe_key) DO UPDATE SET
                value = EXCLUDED.value, unit = EXCLUDED.unit, authority = EXCLUDED.authority,
                provenance = EXCLUDED.provenance, note = EXCLUDED.note',
            [
                $userId,
                $metric,
                $day,
                $value,
                $unit,
                $authority,
                json_encode($provenance === [] ? new \stdClass() : $provenance, JSON_THROW_ON_ERROR),
                $note,
                $dedupe,
            ]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function findPoint(int $userId, string $dedupe): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, metric, day, value, unit, authority, provenance, note
             FROM health_points WHERE user_id = ? AND dedupe_key = ?',
            [$userId, $dedupe]
        );

        return $row ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestDaily(int $userId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT day, sleep_minutes, hrv_ms, resting_hr, steps, readiness_score, readiness_confidence,
                    sleep_authority, hrv_authority, resting_authority, steps_authority
             FROM health_daily WHERE user_id = ? ORDER BY day DESC LIMIT 1',
            [$userId]
        );

        return $row === null ? null : $this->dailyRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function daily(int $userId, string $day): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT day, sleep_minutes, hrv_ms, resting_hr, steps, readiness_score, readiness_confidence,
                    sleep_authority, hrv_authority, resting_authority, steps_authority
             FROM health_daily WHERE user_id = ? AND day = ?',
            [$userId, $day]
        );

        return $row === null ? null : $this->dailyRow($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function dailyRow(array $row): array
    {
        return [
            'day'                   => (string) $row['day'],
            'sleep_minutes'         => $row['sleep_minutes'] !== null ? (float) $row['sleep_minutes'] : null,
            'hrv_ms'                => $row['hrv_ms'] !== null ? (float) $row['hrv_ms'] : null,
            'resting_hr'            => $row['resting_hr'] !== null ? (float) $row['resting_hr'] : null,
            'steps'                 => $row['steps'] !== null ? (float) $row['steps'] : null,
            'readiness_score'       => $row['readiness_score'] !== null ? (int) $row['readiness_score'] : null,
            'readiness_confidence'  => (float) $row['readiness_confidence'],
            'sleep_authority'       => $row['sleep_authority'] !== null ? (string) $row['sleep_authority'] : null,
            'hrv_authority'         => $row['hrv_authority'] !== null ? (string) $row['hrv_authority'] : null,
            'resting_authority'     => $row['resting_authority'] !== null ? (string) $row['resting_authority'] : null,
            'steps_authority'       => $row['steps_authority'] !== null ? (string) $row['steps_authority'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function point(array $row): array
    {
        $provenance = is_string($row['provenance'] ?? null) ? json_decode((string) $row['provenance'], true) : ($row['provenance'] ?? []);

        return [
            'id'         => (int) ($row['id'] ?? 0),
            'metric'     => (string) ($row['metric'] ?? ''),
            'day'        => (string) ($row['day'] ?? ''),
            'value'      => isset($row['value']) && $row['value'] !== null ? (float) $row['value'] : null,
            'unit'       => (string) ($row['unit'] ?? ''),
            'authority'  => (string) ($row['authority'] ?? ''),
            'provenance' => is_array($provenance) ? $provenance : [],
            'note'       => isset($row['note']) && $row['note'] !== null ? (string) $row['note'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function workout(array $row): array
    {
        $provenance = is_string($row['provenance'] ?? null) ? json_decode((string) $row['provenance'], true) : ($row['provenance'] ?? []);

        return [
            'id'         => (int) ($row['id'] ?? 0),
            'title'      => (string) ($row['title'] ?? ''),
            'started_at' => (string) ($row['started_at'] ?? ''),
            'ended_at'   => isset($row['ended_at']) && $row['ended_at'] !== null ? (string) $row['ended_at'] : null,
            'minutes'    => isset($row['minutes']) && $row['minutes'] !== null ? (float) $row['minutes'] : null,
            'authority'  => (string) ($row['authority'] ?? ''),
            'provenance' => is_array($provenance) ? $provenance : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manualProvenance(?string $note): array
    {
        $out = [
            'provider'    => 'manual',
            'source'      => 'life-dashboard',
            'observed_at' => gmdate('Y-m-d H:i:s'),
            'ingested_at' => gmdate('Y-m-d H:i:s'),
        ];
        if ($note !== null) {
            $out['note'] = $note;
        }

        return $out;
    }

    private function rememberSync(int $userId, ?string $error): void
    {
        $existing = $this->db->fetchOne('SELECT user_id, last_ok_at FROM health_sync WHERE user_id = ?', [$userId]);
        $okAt = $error === null ? gmdate('Y-m-d H:i:s') : ($existing['last_ok_at'] ?? null);
        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO health_sync (user_id, last_ok_at, last_error, last_at) VALUES (?, ?, ?, (now() AT TIME ZONE \'UTC\'))',
                [$userId, $okAt, $error]
            );
            return;
        }
        $this->db->execute(
            'UPDATE health_sync SET last_ok_at = ?, last_error = ?, last_at = (now() AT TIME ZONE \'UTC\') WHERE user_id = ?',
            [$okAt, $error, $userId]
        );
    }

    private function note(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 500) {
            throw ValidationException::withFields(['note' => 'Keep the note under 500 characters.']);
        }

        return $text;
    }

    private function optionalDay(mixed $value): string
    {
        if ($value === null || $value === '') {
            return $this->clock->today();
        }

        return $this->day($value, 'day');
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function range(string $from, string $to): array
    {
        if ($from === '' && $to === '') {
            $to = $this->clock->today();
            $from = (new \DateTimeImmutable($to))->modify('-6 days')->format('Y-m-d');
        }
        $from = $this->day($from, 'from');
        $to = $this->day($to, 'to');
        if ($from > $to) {
            throw ValidationException::withFields(['from' => 'The start day is after the end day.']);
        }

        return [$from, $to];
    }

    private function day(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) !== 1) {
            throw ValidationException::withFields([$field => 'Use YYYY-MM-DD.']);
        }

        return $text;
    }
}
