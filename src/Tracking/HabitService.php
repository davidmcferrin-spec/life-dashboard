<?php

declare(strict_types=1);

namespace Dashboard\Tracking;

use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Widgets\GoalLog;

/**
 * HabitService — daily check-ins, streaks and weekly progress.
 *
 * A check-off habit is done when its value is 1. A count habit (daily_target)
 * is done only when the day's total reaches that target. A streak stays alive
 * through today until midnight: if today is not done yet, it counts back from
 * yesterday. See HabitProgress::DONE_MEANS.
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

        $out = [];
        foreach ($habits as $h) {
            $out[] = $this->habitRow($userId, $h, $today, $range);
        }

        return ['today' => $today, 'days' => $range, 'habits' => $out];
    }

    /**
     * Every active habit and every number metric that has a goal, for today.
     *
     * @return array{today: string, done_means: string, items: list<array<string, mixed>>}
     */
    public function todayStatus(int $userId): array
    {
        $today = $this->clock->today();
        $items = [];
        foreach ($this->metrics->list($userId, 'habit') as $habit) {
            $row = $this->habitRow($userId, $habit, $today, [$today]);
            unset($row['history']);
            $items[] = $row;
        }
        foreach ($this->metrics->list($userId, 'number') as $metric) {
            $goal = $metric['config']['goal'] ?? null;
            if (!is_numeric($goal) || (float) $goal <= 0.0) {
                continue;
            }
            $items[] = $this->goalRow($userId, $metric, $today);
        }

        return [
            'today'      => $today,
            'done_means' => HabitProgress::DONE_MEANS,
            'items'      => $items,
        ];
    }

    /**
     * Add $amount to a count habit or a number goal in one write.
     *
     * A habit linked to a number metric adds to that metric. A check-off habit
     * stops at 1. A count habit keeps the running total.
     *
     * @return array<string, mixed>
     */
    public function incrementDatapoint(int $userId, ?int $metricId, ?string $key, float $amount, ?string $date, string $source): array
    {
        if (($metricId === null || $metricId < 1) && ($key === null || trim($key) === '')) {
            throw ValidationException::withFields(['metric_id' => 'Pass a metric id or a key.']);
        }
        if (!is_finite($amount) || $amount == 0.0 || abs($amount) > 1e9) {
            throw ValidationException::withFields(['amount' => 'Amount must be a non-zero number.']);
        }
        $metric = $metricId !== null && $metricId > 0
            ? $this->metrics->get($userId, $metricId)
            : $this->metrics->findByKey($userId, MetricService::cleanKey((string) $key));
        if ($metric === null) {
            throw new NotFoundException('Metric not found.');
        }
        if ($metric['archived']) {
            throw new ValidationException('Metric is archived.');
        }
        if ($metric['kind'] === 'habit') {
            $date = $this->checkDate($date);
            $linked = $metric['config']['goal_metric_id'] ?? null;
            if ($linked !== null) {
                $target = $this->metrics->get($userId, (int) $linked);
                if ($target['kind'] !== 'number' || $target['archived']) {
                    throw ValidationException::withFields(['metric_id' => 'The linked metric is not a number goal.']);
                }
                $this->addNumber($userId, $target, $amount, $date, $source);
            } else {
                $cap = $this->isCountHabit($metric) ? null : 1.0;
                $this->points->addToDay(
                    $userId,
                    (int) $metric['id'],
                    $amount,
                    $date,
                    $source,
                    'day:' . $date,
                    $cap
                );
            }

            return $this->habitState($userId, $metric, $date) + ['amount' => $amount];
        }
        if ($metric['kind'] === 'number') {
            $date = $this->goalDate($date);
            $stored = $this->addNumber($userId, $metric, $amount, $date, $source);

            return $this->goalRow($userId, $metric, $date) + [
                'amount'     => $amount,
                'value'      => (float) $stored['value'],
                'dedupe_key' => $stored['dedupe_key'],
            ];
        }

        throw ValidationException::withFields(['metric_id' => 'Increment a habit or a number goal.']);
    }

    /**
     * Check off habits that match this meal. Eat-at-home is skipped when the meal was out.
     *
     * @return list<array{id: int, key: string, name: string}>
     */
    public function markForMeal(int $userId, string $date, string $mealType, bool $eatenOut, string $source): array
    {
        $marked = [];
        foreach ($this->metrics->list($userId, 'habit') as $habit) {
            if (!HabitProgress::matchesMeal((string) $habit['name'], (string) $habit['key'], $mealType, $eatenOut)) {
                continue;
            }
            try {
                $this->set($userId, (int) $habit['id'], true, $date, $source);
            } catch (ValidationException) {
                continue;
            }
            $marked[] = [
                'id'   => (int) $habit['id'],
                'key'  => (string) $habit['key'],
                'name' => (string) $habit['name'],
            ];
        }

        return $marked;
    }

    /**
     * Flip a habit for a day (default today) and return the resulting state.
     *
     * A count habit that is still short of its target is set to that target.
     * Clearing a day removes the total.
     *
     * @return array<string, mixed>
     */
    public function toggle(int $userId, int $metricId, ?string $date = null, string $source = 'widget'): array
    {
        $metric = $this->requireHabit($userId, $metricId);
        $date = $this->checkDate($date);
        $state = $this->habitState($userId, $metric, $date);
        if ($state['done']) {
            $this->points->deleteForDay($userId, $metricId, $date);
        } else {
            $this->markDone($userId, $metric, $date, $source);
        }

        return $this->habitState($userId, $metric, $date);
    }

    /**
     * Set a habit explicitly. Calling it again with the same done flag changes nothing.
     *
     * @return array<string, mixed>
     */
    public function set(int $userId, int $metricId, bool $done, ?string $date = null, string $source = 'api'): array
    {
        $metric = $this->requireHabit($userId, $metricId);
        $date = $this->checkDate($date);
        if ($done) {
            $state = $this->habitState($userId, $metric, $date);
            if (!$state['done']) {
                $this->markDone($userId, $metric, $date, $source);
            }
        } else {
            $this->points->deleteForDay($userId, $metricId, $date);
        }

        return $this->habitState($userId, $metric, $date);
    }

    /**
     * Check off habits whose linked metric reached its threshold on $localDate.
     *
     * Only completes. A lower value leaves a manual check-in in place. The
     * habit write itself does not call back here (habits are not number metrics).
     */
    public function completeFromMetric(int $userId, int $metricId, string $localDate, string $source): void
    {
        try {
            $sourceMetric = $this->metrics->get($userId, $metricId);
        } catch (NotFoundException) {
            return;
        }
        if ($sourceMetric['kind'] !== 'number' || $sourceMetric['archived']) {
            return;
        }

        $last = $this->points->aggregate($userId, $metricId, 'last', $localDate, $localDate);
        if ($last === null) {
            return;
        }

        $today = $this->clock->today();
        $oldest = Clock::shiftDate($today, self::MAX_BACKFILL_DAYS);
        if ($localDate > $today || $localDate < $oldest) {
            return;
        }

        foreach ($this->metrics->list($userId, 'habit') as $habit) {
            $linked = $habit['config']['goal_metric_id'] ?? null;
            if ($linked === null || (int) $linked !== $metricId) {
                continue;
            }
            $threshold = $habit['config']['goal_threshold'] ?? ($sourceMetric['config']['goal'] ?? null);
            if ($threshold === null || !is_numeric($threshold)) {
                continue;
            }
            if ($last < (float) $threshold) {
                continue;
            }
            if ($this->points->datesWithValue($userId, (int) $habit['id'], $localDate, $localDate) !== []) {
                continue;
            }
            try {
                $this->set($userId, (int) $habit['id'], true, $localDate, $source);
            } catch (ValidationException) {
                // The check-in window or the habit changed between the read and the write.
            }
        }
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
        $this->assertGoalMetric($userId, $config);
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
            $this->assertGoalMetric($userId, $patch['config']);
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

    /**
     * @param array<string, mixed> $metric
     * @param list<string> $range
     * @return array<string, mixed>
     */
    private function habitRow(int $userId, array $metric, string $today, array $range): array
    {
        $picture = $this->picture($userId, $metric, $today);
        $done = $picture['done_dates'];
        $doneSet = array_flip($done);
        $weekStart = self::weekStart($today);
        $weekCount = count(array_filter($done, static fn (string $d): bool => $d >= $weekStart));
        $state = $picture['state'];
        $config = is_array($metric['config']) ? $metric['config'] : [];

        return [
            'id'              => (int) $metric['id'],
            'metric_id'       => (int) $metric['id'],
            'key'             => (string) $metric['key'],
            'name'            => (string) $metric['name'],
            'kind'            => 'habit',
            'date'            => $today,
            'emoji'           => $config['emoji'] ?? null,
            'color'           => $config['color'] ?? null,
            'target_per_week' => $config['target_per_week'] ?? null,
            'goal_metric_id'  => isset($config['goal_metric_id']) ? (int) $config['goal_metric_id'] : null,
            'goal_threshold'  => isset($config['goal_threshold']) ? (float) $config['goal_threshold'] : null,
            'daily_target'    => isset($config['daily_target']) ? (float) $config['daily_target'] : null,
            'target'          => $state['target'],
            'threshold'       => $state['threshold'],
            'progress'        => $state['progress'],
            'done'            => $state['done'],
            'done_today'      => $state['done'],
            'remaining'       => $state['remaining'],
            'streak'          => self::currentStreak($done, $today),
            'best_streak'     => self::bestStreak($done),
            'week_count'      => $weekCount,
            'history'         => array_map(static fn (string $d): bool => isset($doneSet[$d]), $range),
        ];
    }

    /**
     * State for $date. The streak is still anchored on today.
     *
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    private function habitState(int $userId, array $metric, string $date): array
    {
        $today = $this->clock->today();
        $row = $this->habitRow($userId, $metric, $today, [$today]);
        unset($row['history']);
        if ($date === $today) {
            return $row;
        }
        $state = $this->picture($userId, $metric, $date)['state'];
        $row['date'] = $date;
        $row['target'] = $state['target'];
        $row['threshold'] = $state['threshold'];
        $row['progress'] = $state['progress'];
        $row['done'] = $state['done'];
        $row['remaining'] = $state['remaining'];

        return $row;
    }

    /**
     * @param array<string, mixed> $metric
     * @return array{state: array{target: float, threshold: float, progress: float, done: bool, remaining: float}, done_dates: list<string>}
     */
    private function picture(int $userId, array $metric, string $date): array
    {
        $today = $this->clock->today();
        $end = $date > $today ? $date : $today;
        $values = $this->points->valuesByDate(
            $userId,
            (int) $metric['id'],
            Clock::shiftDate($end, self::STREAK_WINDOW_DAYS),
            $end
        );
        $value = (float) ($values[$date] ?? 0.0);
        $linked = isset($metric['config']['goal_metric_id']) ? $this->linkedTarget($userId, $metric) : null;
        if ($linked !== null) {
            $progress = $linked['missing'] ? 0.0 : $this->linkedValue($userId, $linked['id'], $date);
            $checked = HabitProgress::countsAsChecked($value, 1.0, false);
            $state = HabitProgress::state($progress, $linked['target'], $checked);
            $doneDates = $this->datesWhere($values, static fn (float $v): bool => HabitProgress::countsAsChecked($v, 1.0, false));
        } elseif ($this->isCountHabit($metric)) {
            $target = (float) $metric['config']['daily_target'];
            $checked = HabitProgress::countsAsChecked($value, $target, true);
            $state = HabitProgress::state($value, $target, $checked);
            $doneDates = $this->datesWhere($values, static fn (float $v): bool => HabitProgress::countsAsChecked($v, $target, true));
        } else {
            $checked = HabitProgress::countsAsChecked($value, 1.0, false);
            $state = HabitProgress::state($checked ? 1.0 : 0.0, 1.0, $checked);
            $doneDates = $this->datesWhere($values, static fn (float $v): bool => HabitProgress::countsAsChecked($v, 1.0, false));
        }

        return ['state' => $state, 'done_dates' => $doneDates];
    }

    /**
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    private function goalRow(int $userId, array $metric, string $date): array
    {
        $today = $this->clock->today();
        $end = $date > $today ? $date : $today;
        $values = $this->points->valuesByDate(
            $userId,
            (int) $metric['id'],
            Clock::shiftDate($end, self::STREAK_WINDOW_DAYS),
            $end
        );
        $goal = (float) ($metric['config']['goal'] ?? 0);
        $down = ($metric['config']['goal_direction'] ?? 'up') === 'down';
        $has = array_key_exists($date, $values);
        $progress = $has ? (float) $values[$date] : 0.0;
        $met = $has && $progress + 1e-9 >= $goal;
        $state = $down
            ? HabitProgress::downState($progress, $goal, $has)
            : HabitProgress::state($progress, $goal, $met);
        $doneDates = $this->datesWhere($values, static function (float $value) use ($down, $goal): bool {
            return $down ? $value <= $goal + 1e-9 : $value + 1e-9 >= $goal;
        });

        return [
            'kind'       => 'goal',
            'id'         => (int) $metric['id'],
            'metric_id'  => (int) $metric['id'],
            'key'        => (string) $metric['key'],
            'name'       => (string) $metric['name'],
            'date'       => $date,
            'unit'       => $metric['unit'] ?? null,
            'target'     => $state['target'],
            'threshold'  => $state['threshold'],
            'progress'   => $state['progress'],
            'done'       => $state['done'],
            'done_today' => $date === $today && $state['done'],
            'remaining'  => $state['remaining'],
            'streak'     => self::currentStreak($doneDates, $today),
        ];
    }

    /**
     * @param array<string, float> $values
     * @param callable(float): bool $met
     * @return list<string>
     */
    private function datesWhere(array $values, callable $met): array
    {
        $dates = [];
        foreach ($values as $day => $value) {
            if ($met((float) $value)) {
                $dates[] = (string) $day;
            }
        }

        return $dates;
    }

    /**
     * @param array<string, mixed> $metric
     */
    private function isCountHabit(array $metric): bool
    {
        if (isset($metric['config']['goal_metric_id'])) {
            return false;
        }
        $target = $metric['config']['daily_target'] ?? null;

        return is_numeric($target) && (float) $target > 0.0;
    }

    /**
     * @param array<string, mixed> $metric
     */
    private function markDone(int $userId, array $metric, string $date, string $source): void
    {
        if ($this->isCountHabit($metric)) {
            $this->points->putDaily(
                $userId,
                (int) $metric['id'],
                (float) $metric['config']['daily_target'],
                $date,
                $source,
                'day:' . $date
            );
            return;
        }
        $this->points->record($userId, (int) $metric['id'], ['value' => 1, 'date' => $date, 'source' => $source]);
    }

    /**
     * @param array<string, mixed> $metric
     * @return array<string, mixed>
     */
    private function addNumber(int $userId, array $metric, float $amount, string $date, string $source): array
    {
        $key = GoalLog::dedupeKey(GoalLog::prefix([], $metric), $date);

        return $this->points->addToDay($userId, (int) $metric['id'], $amount, $date, $source, $key, null);
    }

    /**
     * @param array<string, mixed> $habit
     * @return array{id: int, target: float, missing: bool}
     */
    private function linkedTarget(int $userId, array $habit): array
    {
        $id = (int) $habit['config']['goal_metric_id'];
        try {
            $metric = $this->metrics->get($userId, $id);
        } catch (NotFoundException) {
            return ['id' => $id, 'target' => 1.0, 'missing' => true];
        }
        $threshold = $habit['config']['goal_threshold'] ?? ($metric['config']['goal'] ?? null);
        $target = is_numeric($threshold) && (float) $threshold > 0 ? (float) $threshold : 1.0;

        return ['id' => $id, 'target' => $target, 'missing' => $metric['kind'] !== 'number' || $metric['archived']];
    }

    private function linkedValue(int $userId, int $metricId, string $date): float
    {
        $values = $this->points->valuesByDate($userId, $metricId, $date, $date);

        return (float) ($values[$date] ?? 0.0);
    }

    private function goalDate(?string $date): string
    {
        $today = $this->clock->today();
        if ($date === null || $date === '') {
            return $today;
        }
        try {
            $date = Clock::normalizeDate($date);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withFields(['date' => $e->getMessage()]);
        }
        if ($date > $today) {
            throw ValidationException::withFields(['date' => 'Date cannot be in the future.']);
        }

        return $date;
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
            'goal_metric_id'  => isset($config['goal_metric_id']) ? (int) $config['goal_metric_id'] : null,
            'goal_threshold'  => isset($config['goal_threshold']) ? (float) $config['goal_threshold'] : null,
            'daily_target'    => isset($config['daily_target']) ? (float) $config['daily_target'] : null,
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
        if (array_key_exists('goal_metric_id', $input)) {
            $raw = $input['goal_metric_id'];
            if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
                unset($config['goal_metric_id'], $config['goal_threshold']);
            } else {
                $config['goal_metric_id'] = $raw;
            }
        }
        if (array_key_exists('goal_threshold', $input)) {
            $threshold = $input['goal_threshold'];
            if ($threshold === null || $threshold === '') {
                unset($config['goal_threshold']);
            } else {
                $config['goal_threshold'] = $threshold;
            }
        }
        if (array_key_exists('daily_target', $input)) {
            $daily = $input['daily_target'];
            if ($daily === null || $daily === '' || $daily === 0 || $daily === '0') {
                unset($config['daily_target']);
            } else {
                $config['daily_target'] = $daily;
            }
        }
        return MetricService::validateConfig('habit', $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function assertGoalMetric(int $userId, array $config): void
    {
        if (!isset($config['goal_metric_id'])) {
            return;
        }
        try {
            $metric = $this->metrics->get($userId, (int) $config['goal_metric_id']);
        } catch (NotFoundException) {
            throw ValidationException::withFields(['goal_metric_id' => 'Metric not found.']);
        }
        if ($metric['kind'] !== 'number' || $metric['archived']) {
            throw ValidationException::withFields(['goal_metric_id' => 'Link a number metric.']);
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function touchesAppearance(array $input): bool
    {
        foreach (['icon', 'emoji', 'color', 'frequency', 'target', 'target_per_week', 'goal_metric_id', 'goal_threshold', 'daily_target'] as $key) {
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
