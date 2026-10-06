<?php

declare(strict_types=1);

namespace Dashboard\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * SqlTime — database-agnostic time cutoffs.
 *
 * Legacy queries used SQLite-only functions such as datetime('now') and
 * datetime(col, '+30 minutes'). Those do not exist in PostgreSQL, so cutoffs
 * are now computed in PHP and bound as 'Y-m-d H:i:s' parameters, which
 * compare correctly against TIMESTAMP columns on every driver.
 *
 * Storage conventions in this app:
 *   - Columns written with CURRENT_TIMESTAMP (last_checked_at, created_at …) are UTC.
 *   - User-entered wall-clock values (user_flight_watches.departure_at) are in APP_TIMEZONE.
 */
final class SqlTime
{
    public const FORMAT = 'Y-m-d H:i:s';

    /** Current UTC time, formatted for SQL. */
    public static function utcNow(?DateTimeImmutable $now = null): string
    {
        return self::toUtc($now ?? new DateTimeImmutable('now'))->format(self::FORMAT);
    }

    /**
     * UTC time $minutes ago. Use for "last_checked_at <= ?" poll cutoffs
     * (equivalent to legacy `datetime(last_checked_at, '+N minutes') <= datetime('now')`).
     */
    public static function utcMinutesAgo(int $minutes, ?DateTimeImmutable $now = null): string
    {
        return self::toUtc($now ?? new DateTimeImmutable('now'))
            ->modify('-' . max(0, $minutes) . ' minutes')
            ->format(self::FORMAT);
    }

    /**
     * Wall-clock time in $timezone, $hours from now. Use for columns stored in
     * APP_TIMEZONE (e.g. "departure_at <= ?" for the 24 h flight poll window).
     */
    public static function localHoursFromNow(int $hours, string $timezone, ?DateTimeImmutable $now = null): string
    {
        $tz  = new DateTimeZone($timezone);
        $ref = ($now ?? new DateTimeImmutable('now'))->setTimezone($tz);

        return $ref->modify(($hours >= 0 ? '+' : '') . $hours . ' hours')->format(self::FORMAT);
    }

    private static function toUtc(DateTimeImmutable $time): DateTimeImmutable
    {
        return $time->setTimezone(new DateTimeZone('UTC'));
    }
}
