<?php

declare(strict_types=1);

namespace Dashboard\Money;

/**
 * Due dates for bills and the next payday.
 * Pure date math so the page and the API share one calendar.
 */
final class BillSchedule
{
    public const CADENCES = ['monthly', 'biweekly', 'yearly'];

    public const PAYDAY_CADENCES = ['weekly', 'biweekly', 'monthly'];

    public const JOB_TYPES = ['sinking', 'buffer', 'gifts', 'trip', 'escrow', 'deep_clean', 'other'];

    /** @var array<string, string> */
    public const JOB_LABELS = [
        'sinking'    => 'Sinking fund',
        'buffer'     => 'Buffer',
        'gifts'      => 'Christmas gifts',
        'trip'       => 'Trip',
        'escrow'     => 'Escrow',
        'deep_clean' => 'Deep clean',
        'other'      => 'Other',
    ];

    public const SOON_DAYS = 3;

    public static function clampDate(int $year, int $month, int $day): string
    {
        $month = max(1, min(12, $month));
        $day = max(1, $day);
        $last = (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
        if ($day > $last) {
            $day = $last;
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /**
     * Dates from $from through $to, inclusive.
     *
     * @return list<string>
     */
    public static function occurrences(
        string $from,
        string $to,
        string $cadence,
        ?int $dueDay,
        ?int $dueMonth,
        ?string $anchor,
    ): array {
        if ($from > $to) {
            return [];
        }
        if ($cadence === 'biweekly') {
            if ($anchor === null || $anchor === '') {
                return [];
            }
            return self::stepDates($from, $to, $anchor, 14);
        }
        if ($cadence === 'yearly') {
            if ($dueDay === null || $dueMonth === null) {
                return [];
            }
            $out = [];
            $startYear = (int) substr($from, 0, 4);
            $endYear = (int) substr($to, 0, 4);
            for ($year = $startYear; $year <= $endYear; $year++) {
                $date = self::clampDate($year, $dueMonth, $dueDay);
                if ($date >= $from && $date <= $to) {
                    $out[] = $date;
                }
            }
            return $out;
        }
        if ($dueDay === null) {
            return [];
        }
        $cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
        $end = new \DateTimeImmutable(substr($to, 0, 7) . '-01');
        $out = [];
        for ($guard = 0; $guard < 48 && $cursor <= $end; $guard++) {
            $date = self::clampDate((int) $cursor->format('Y'), (int) $cursor->format('n'), $dueDay);
            if ($date >= $from && $date <= $to) {
                $out[] = $date;
            }
            $cursor = $cursor->modify('+1 month');
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    public static function stepDates(string $from, string $to, string $anchor, int $stepDays): array
    {
        if ($stepDays < 1 || $from > $to || $anchor === '') {
            return [];
        }
        $cursor = new \DateTimeImmutable($anchor);
        $start = new \DateTimeImmutable($from);
        if ($cursor < $start) {
            $days = (int) $cursor->diff($start)->days;
            $steps = intdiv($days, $stepDays);
            $cursor = $cursor->modify('+' . ($steps * $stepDays) . ' days');
            if ($cursor < $start) {
                $cursor = $cursor->modify('+' . $stepDays . ' days');
            }
        }
        $out = [];
        for ($guard = 0; $guard < 80 && $cursor->format('Y-m-d') <= $to; $guard++) {
            $iso = $cursor->format('Y-m-d');
            if ($iso >= $from) {
                $out[] = $iso;
            }
            $cursor = $cursor->modify('+' . $stepDays . ' days');
        }
        return $out;
    }

    public static function nextOnOrAfter(
        string $today,
        string $cadence,
        ?int $dueDay,
        ?int $dueMonth,
        ?string $anchor,
    ): ?string {
        $to = (new \DateTimeImmutable($today))->modify('+400 days')->format('Y-m-d');
        $found = self::occurrences($today, $to, $cadence, $dueDay, $dueMonth, $anchor);
        return $found[0] ?? null;
    }

    /**
     * An explicit next payday on or after today wins.
     * Otherwise the anchor rolls forward by the cadence.
     */
    public static function paydayOn(string $today, string $cadence, ?string $anchor, ?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '' && $explicit >= $today) {
            return $explicit;
        }
        if ($anchor === null || $anchor === '') {
            return null;
        }
        if ($cadence === 'monthly') {
            $day = (int) substr($anchor, 8, 2);
            if ($day < 1) {
                return null;
            }
            return self::nextOnOrAfter($today, 'monthly', $day, null, null);
        }
        $step = $cadence === 'weekly' ? 7 : 14;
        $to = (new \DateTimeImmutable($today))->modify('+400 days')->format('Y-m-d');
        $found = self::stepDates($today, $to, $anchor, $step);
        return $found[0] ?? null;
    }

    public static function previousPayday(string $next, string $cadence): string
    {
        $date = new \DateTimeImmutable($next);
        if ($cadence === 'weekly') {
            return $date->modify('-7 days')->format('Y-m-d');
        }
        if ($cadence === 'monthly') {
            $prev = $date->modify('first day of last month');
            return self::clampDate((int) $prev->format('Y'), (int) $prev->format('n'), (int) $date->format('j'));
        }
        return $date->modify('-14 days')->format('Y-m-d');
    }

    /**
     * How far back an unpaid occurrence still counts as past due.
     * One cadence gap, so adding a bill does not invent a year of missed cycles.
     */
    public static function pastWindowStart(string $today, string $cadence): string
    {
        $date = new \DateTimeImmutable($today);
        if ($cadence === 'biweekly') {
            return $date->modify('-14 days')->format('Y-m-d');
        }
        if ($cadence === 'yearly') {
            return $date->modify('-370 days')->format('Y-m-d');
        }
        return $date->modify('-31 days')->format('Y-m-d');
    }

    public static function status(string $today, ?string $dueOn, bool $paid, bool $skipped): string
    {
        if ($skipped) {
            return 'skipped';
        }
        if ($paid) {
            return 'paid';
        }
        if ($dueOn === null || $dueOn === '') {
            return 'upcoming';
        }
        if ($dueOn < $today) {
            return 'past_due';
        }
        $soon = (new \DateTimeImmutable($today))->modify('+' . self::SOON_DAYS . ' days')->format('Y-m-d');
        if ($dueOn <= $soon) {
            return 'due_soon';
        }
        return 'upcoming';
    }

    public static function progress(float $funded, float $target): float
    {
        if ($funded < 0) {
            return 0.0;
        }
        if ($target <= 0) {
            return $funded > 0 ? 100.0 : 0.0;
        }
        return round(min(100, ($funded / $target) * 100), 1);
    }

    /**
     * True when the target is met. False when the date has passed and it is short.
     * Null while the date is still ahead and the envelope is not full.
     */
    public static function onTrack(string $today, ?string $targetOn, float $funded, float $target): ?bool
    {
        if ($targetOn === null || $targetOn === '' || $target <= 0) {
            return null;
        }
        if ($funded + 0.009 >= $target) {
            return true;
        }
        if ($targetOn < $today) {
            return false;
        }
        return null;
    }
}
