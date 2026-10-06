<?php

declare(strict_types=1);

namespace Dashboard\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Clock — "now" and the user's calendar day, injectable for tests.
 *
 * Storage convention: timestamps are UTC wall clock ('Y-m-d H:i:s');
 * calendar days (datapoints.local_date, habit days) are in APP_TIMEZONE.
 */
final class Clock
{
    private DateTimeZone $tz;

    public function __construct(string $timezone, private readonly ?DateTimeImmutable $fixedNow = null)
    {
        $this->tz = new DateTimeZone($timezone);
    }

    public static function fromConfig(): self
    {
        return new self(\Dashboard\Config::get('APP_TIMEZONE', 'America/Chicago'));
    }

    public function timezone(): DateTimeZone
    {
        return $this->tz;
    }

    public function now(): DateTimeImmutable
    {
        return ($this->fixedNow ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone('UTC'));
    }

    /** Today's date (Y-m-d) in the local timezone. */
    public function today(): string
    {
        return $this->now()->setTimezone($this->tz)->format('Y-m-d');
    }

    /** UTC SQL timestamp for a moment. */
    public function utcString(DateTimeImmutable $t): string
    {
        return $t->setTimezone(new DateTimeZone('UTC'))->format(SqlTime::FORMAT);
    }

    /** Local calendar date of a moment. */
    public function localDateOf(DateTimeImmutable $t): string
    {
        return $t->setTimezone($this->tz)->format('Y-m-d');
    }

    /**
     * Parse a client-supplied timestamp. Strings without an offset are read as
     * local time (what a person types); ISO strings with Z/offset are honoured.
     */
    public function parse(string $value): DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('Empty timestamp.');
        }
        try {
            return new DateTimeImmutable($value, $this->tz);
        } catch (\Exception) {
            throw new \InvalidArgumentException('Invalid timestamp: ' . mb_strimwidth($value, 0, 40, '…'));
        }
    }

    /** Validate a Y-m-d date string and return it normalised. */
    public static function normalizeDate(string $date): string
    {
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        return $date;
    }

    /** Local midday of a calendar date, as a UTC timestamp — used for per-day entries. */
    public function noonOf(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable(self::normalizeDate($date) . ' 12:00:00', $this->tz);
    }

    /** Y-m-d that is $days before $date (negative = after). */
    public static function shiftDate(string $date, int $days): string
    {
        return (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('UTC')))
            ->modify(($days >= 0 ? '-' : '+') . abs($days) . ' days')
            ->format('Y-m-d');
    }
}
