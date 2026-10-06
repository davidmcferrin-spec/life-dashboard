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
