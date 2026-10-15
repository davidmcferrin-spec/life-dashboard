<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;

/**
 * HabitService — daily check-ins, streaks and weekly progress.
 *
 * A habit is "done" on a local calendar day when it has a datapoint with
 * value 1 for that day. A streak stays alive through today until midnight:
 * if today is not done yet, the streak counts back from yesterday.
 */
final class HabitService
{
    /** How far back a check-in can be toggled (fixing a missed day). */
    public const MAX_BACKFILL_DAYS = 30;
    /** Window scanned for current/best streak calculations. */
    public const STREAK_WINDOW_DAYS = 400;

    public function __construct(
        private readonly MetricService $metrics,
        private readonly DatapointService $points,
        private readonly Clock $clock,
    ) {}

    /**
     * Everything the habits widget needs in one call.
     *
     * @param list<int>|null $metricIds null = all active habits
     * @return array{today: string, days: list<string>, habits: list<array<string, mixed>>}
     */
    public function summary(int $userId, ?array $metricIds = null, int $days = 7): array
    {
        $days  = max(1, min(31, $days));
        $today = $this->clock->today();
        $range = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $range[] = Clock::shiftDate($today, $i);
        }

        $habits = $this->metrics->list($userId, 'habit');
        if ($metricIds !== null) {
            $wanted = array_flip($metricIds);
            $habits = array_values(array_filter($habits, static fn (array $m): bool => isset($wanted[$m['id']])));
        }

        $weekStart = self::weekStart($today);
        $out = [];
        foreach ($habits as $h) {
            $done = $this->points->datesWithValue(
                $userId,
                (int) $h['id'],
                Clock::shiftDate($today, self::STREAK_WINDOW_DAYS),
                $today
            );
            $doneSet = array_flip($done);
            $weekCount = count(array_filter($done, static fn (string $d): bool => $d >= $weekStart));

            $out[] = [
                'id'              => $h['id'],
                'key'             => $h['key'],
                'name'            => $h['name'],
                'emoji'           => $h['config']['emoji'] ?? null,
                'color'           => $h['config']['color'] ?? null,
                'target_per_week' => $h['config']['target_per_week'] ?? null,
                'done_today'      => isset($doneSet[$today]),
                'streak'          => self::currentStreak($done, $today),
                'best_streak'     => self::bestStreak($done),
                'week_count'      => $weekCount,
                'history'         => array_map(static fn (string $d): bool => isset($doneSet[$d]), $range),
            ];
        }

        return ['today' => $today, 'days' => $range, 'habits' => $out];
    }

    /**
     * Flip a habit for a day (default today). Returns the new state.
     *
     * @return array{metric_id: int, date: string, done: bool}
     */
    public function toggle(int $userId, int $metricId, ?string $date = null, string $source = 'widget'): array
    {
        $metric = $this->metrics->get($userId, $metricId);
        if ($metric['kind'] !== 'habit') {
            throw ValidationException::withFields(['metric' => 'Not a habit.']);
        }
        $date = $this->checkDate($date);

        $isDone = in_array($date, $this->points->datesWithValue($userId, $metricId, $date, $date), true);
        if ($isDone) {
            $this->points->deleteForDay($userId, $metricId, $date);
        } else {
            $this->points->record($userId, $metricId, ['value' => 1, 'date' => $date, 'source' => $source]);
        }

        return ['metric_id' => $metricId, 'date' => $date, 'done' => !$isDone];
    }

    /**
     * Set a habit explicitly (idempotent — for integrations and AI).
     *
     * @return array{metric_id: int, date: string, done: bool}
     */
    public function set(int $userId, int $metricId, bool $done, ?string $date = null, string $source = 'api'): array
    {
        $metric = $this->metrics->get($userId, $metricId);
        if ($metric['kind'] !== 'habit') {
            throw ValidationException::withFields(['metric' => 'Not a habit.']);
        }
        $date = $this->checkDate($date);
        if ($done) {
            $this->points->record($userId, $metricId, ['value' => 1, 'date' => $date, 'source' => $source]);
        } else {
            $this->points->deleteForDay($userId, $metricId, $date);
        }
        return ['metric_id' => $metricId, 'date' => $date, 'done' => $done];
    }

    /**
     * Create a habit. The same key returns the existing habit and leaves it unchanged.
     *
     * @param array<string, mixed> $input name, key|slug, icon|emoji, color, frequency|target|target_per_week, sort_order
     * @return array<string, mixed>
     */
    public function create(int $userId, array $input, string $createdBy = 'user'): array
    {
        $name = $input['name'] ?? null;
        $key = self::readKey($input);
        if ($key !== null) {
            $existing = $this->existingHabit($userId, $key);
            if ($existing !== null) {
                $existing['created'] = false;
                return $existing;
            }
        }

        $config = self::configFrom($input, [], true);
        try {
            $metric = $this->metrics->create($userId, [
                'name'   => $name,
                'kind'   => 'habit',
                'key'    => $key,
                'config' => $config,
            ], $createdBy);
        } catch (ValidationException $e) {
            if ($key !== null && isset($e->fields()['key'])) {
                $existing = $this->existingHabit($userId, $key);
                if ($existing !== null) {
                    $existing['created'] = false;
                    return $existing;
                }
            }
            throw $e;
        }

        if (array_key_exists('sort_order', $input) && $input['sort_order'] !== null && $input['sort_order'] !== '') {
            $metric = $this->metrics->update($userId, (int) $metric['id'], [
                'sort_order' => $input['sort_order'],
            ]);
        }

        $habit = self::present($metric);
        $habit['created'] = true;
        return $habit;
    }

    /**
     * Change name, icon, color, frequency, or sort order. The key stays put.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(int $userId, int $metricId, array $input): array
    {
        $metric = $this->requireHabit($userId, $metricId);
        if (self::readKey($input) !== null && self::readKey($input) !== $metric['key']) {
            throw ValidationException::withFields(['key' => 'Key cannot be changed.']);
        }

        $patch = [];
        if (array_key_exists('name', $input)) {
            $patch['name'] = $input['name'];
        }
        if (self::touchesAppearance($input)) {
            $patch['config'] = self::configFrom($input, $metric['config'], false);
        }
        if (array_key_exists('sort_order', $input) && $input['sort_order'] !== null && $input['sort_order'] !== '') {
            $patch['sort_order'] = $input['sort_order'];
        }

        $updated = $patch === [] ? $metric : $this->metrics->update($userId, $metricId, $patch);
        return self::present($updated);
    }

    /**
     * Hide a habit, or restore it when $archived is false. Check-ins are kept.
     *
     * @return array<string, mixed>
     */
    public function setArchived(int $userId, int $metricId, bool $archived): array
    {
        $this->requireHabit($userId, $metricId);
        return self::present($this->metrics->setArchived($userId, $metricId, $archived));
    }

    /**
     * Display order for the given habit ids. The first id is shown first.
     *
     * @param list<mixed> $ids
     * @return array{habits: list<array<string, mixed>>}
     */
    public function reorder(int $userId, array $ids): array
    {
        if ($ids === [] || !array_is_list($ids)) {
            throw ValidationException::withFields(['ids' => 'ids must be a list of habit ids.']);
        }
        $clean = [];
        foreach ($ids as $id) {
            $parsed = filter_var($id, FILTER_VALIDATE_INT);
            if ($parsed === false || $parsed < 1) {
                throw ValidationException::withFields(['ids' => 'ids must be a list of habit ids.']);
            }
            if (in_array($parsed, $clean, true)) {
                throw ValidationException::withFields(['ids' => 'ids cannot repeat a habit.']);
            }
            $this->requireHabit($userId, $parsed);
            $clean[] = $parsed;
        }
        $this->metrics->reorder($userId, $clean);

        $habits = [];
        foreach ($this->metrics->list($userId, 'habit') as $metric) {
            $habits[] = self::present($metric);
        }
        return ['habits' => $habits];
    }

    /**
     * Days per week. daily is 7, weekdays is 5, weekly is 1, or pass 1–7.
     */
    public static function targetPerWeek(mixed $value): int
    {
        if (is_int($value) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            $n = (int) $value;
            if ($n < 1 || $n > 7) {
                throw ValidationException::withFields(['frequency' => 'Frequency must be daily, weekdays, weekly, or a whole number 1–7.']);
            }
            return $n;
        }
        $word = strtolower(trim((string) $value));
        $word = preg_replace('/[\s_-]+/', ' ', $word) ?? $word;
        return match ($word) {
            'daily', 'day', 'every day', 'everyday' => 7,
            'weekdays', 'weekday' => 5,
            'weekly', 'week', 'once', 'once a week' => 1,
            default => throw ValidationException::withFields(['frequency' => 'Frequency must be daily, weekdays, weekly, or a whole number 1–7.']),
        };
    }

    public static function frequencyLabel(?int $target): ?string
    {
        return match ($target) {
            null    => null,
            7       => 'daily',
            5       => 'weekdays',
            1       => 'weekly',
            default => (string) $target,
        };
    }

    // ------------------------------------------------------------------
    // Pure helpers (unit-tested)
    // ------------------------------------------------------------------

    /**
     * @param list<string> $doneDates Y-m-d, any order
     */
    public static function currentStreak(array $doneDates, string $today): int
    {
        $set = array_flip($doneDates);
        $day = isset($set[$today]) ? $today : Clock::shiftDate($today, 1);
        $n = 0;
        while (isset($set[$day])) {
            $n++;
            $day = Clock::shiftDate($day, 1);
        }
        return $n;
    }

    /**
     * @param list<string> $doneDates Y-m-d, any order
     */
    public static function bestStreak(array $doneDates): int
    {
        if ($doneDates === []) {
            return 0;
        }
        $dates = array_values(array_unique($doneDates));
        sort($dates, SORT_STRING);
        $best = $run = 1;
        for ($i = 1, $n = count($dates); $i < $n; $i++) {
            $run = Clock::shiftDate($dates[$i], 1) === $dates[$i - 1] ? $run + 1 : 1;
            $best = max($best, $run);
        }
        return $best;
    }

    /** Monday of the week containing $date (Y-m-d). */
    public static function weekStart(string $date): string
    {
        $dow = (int) (new \DateTimeImmutable($date . ' 12:00:00'))->format('N'); // 1 = Monday
        return Clock::shiftDate($date, $dow - 1);
    }

    /**
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    private static function present(array $metric): array
    {
        $config = is_array($metric['config'] ?? null) ? $metric['config'] : [];
        $target = isset($config['target_per_week']) ? (int) $config['target_per_week'] : null;
        return [
            'id'              => (int) $metric['id'],
            'key'             => (string) $metric['key'],
            'name'            => (string) $metric['name'],
            'icon'            => $config['emoji'] ?? null,
            'color'           => $config['color'] ?? null,
            'frequency'       => self::frequencyLabel($target),
            'target_per_week' => $target,
            'sort_order'      => (int) $metric['sort_order'],
            'archived'        => (bool) ($metric['archived'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingHabit(int $userId, string $key): ?array
    {
        $existing = $this->metrics->findByKey($userId, $key);
        if ($existing === null) {
            return null;
        }
        if ($existing['kind'] !== 'habit') {
            throw ValidationException::withFields(['key' => "Key '{$key}' belongs to a {$existing['kind']}, not a habit."]);
        }
        return self::present($existing);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireHabit(int $userId, int $metricId): array
    {
        $metric = $this->metrics->get($userId, $metricId);
        if ($metric['kind'] !== 'habit') {
            throw ValidationException::withFields(['metric' => 'Not a habit.']);
        }
        return $metric;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function readKey(array $input): ?string
    {
        $key = self::blankToNull($input['key'] ?? null);
        $slug = self::blankToNull($input['slug'] ?? null);
        if ($key !== null && $slug !== null && $key !== $slug) {
            throw ValidationException::withFields(['key' => 'key and slug must match.']);
        }
        $chosen = $key ?? $slug;
        return $chosen === null ? null : MetricService::cleanKey($chosen);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     * @return array<string, mixed>
     */
    private static function configFrom(array $input, array $existing, bool $creating): array
    {
        $config = $existing;
        $icon = self::pairedValue($input, 'icon', 'emoji');
        if ($icon !== null) {
            if ($icon === '') {
                unset($config['emoji']);
            } else {
                $config['emoji'] = $icon;
            }
        }
        if (array_key_exists('color', $input) && $input['color'] !== null) {
            $color = trim((string) $input['color']);
            if ($color === '') {
                unset($config['color']);
            } else {
                $config['color'] = $color;
            }
        }
        $frequency = self::readFrequency($input, $creating);
        if ($frequency !== null) {
            $config['target_per_week'] = $frequency;
        }
        return MetricService::validateConfig('habit', $config);
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function touchesAppearance(array $input): bool
    {
        foreach (['icon', 'emoji', 'color', 'frequency', 'target', 'target_per_week'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] !== null) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function readFrequency(array $input, bool $defaultDaily): ?int
    {
        $values = [];
        foreach (['frequency', 'target', 'target_per_week'] as $key) {
            if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
                continue;
            }
            $values[] = self::targetPerWeek($input[$key]);
        }
        if ($values === []) {
            return $defaultDaily ? 7 : null;
        }
        if (count(array_unique($values)) > 1) {
            throw ValidationException::withFields(['frequency' => 'frequency and target disagree.']);
        }
        return $values[0];
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function pairedValue(array $input, string $left, string $right): ?string
    {
        $a = array_key_exists($left, $input) ? self::blankToNull($input[$left]) : null;
        $b = array_key_exists($right, $input) ? self::blankToNull($input[$right]) : null;
        $aSet = array_key_exists($left, $input) && $input[$left] !== null;
        $bSet = array_key_exists($right, $input) && $input[$right] !== null;
        if ($aSet && $bSet && $a !== $b) {
            throw ValidationException::withFields([$left => $left . ' and ' . $right . ' must match.']);
        }
        if (!$aSet && !$bSet) {
            return null;
        }
        return $a ?? $b ?? '';
    }

    private static function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function checkDate(?string $date): string
    {
        $today = $this->clock->today();
        if ($date === null || $date === '') {
            return $today;
        }
        $date = Clock::normalizeDate($date);
        if ($date > $today) {
            throw ValidationException::withFields(['date' => 'Cannot check in a future day.']);
        }
        if ($date < Clock::shiftDate($today, self::MAX_BACKFILL_DAYS)) {
            throw ValidationException::withFields(['date' => 'Only the last ' . self::MAX_BACKFILL_DAYS . ' days can be changed.']);
        }
        return $date;
    }
}
