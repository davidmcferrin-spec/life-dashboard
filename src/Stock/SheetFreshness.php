<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use DateTimeImmutable;
use DateTimeZone;

/** Whether a stored pull is still inside the market-hours schedule. */
final class SheetFreshness
{
    public static function stale(?string $lastSyncedAt, DateTimeImmutable $nowUtc, DateTimeZone $timezone, string $status): bool
    {
        if ($status === 'error') {
            return true;
        }
        if ($lastSyncedAt === null || trim($lastSyncedAt) === '') {
            return true;
        }
        try {
            $synced = new DateTimeImmutable($lastSyncedAt, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return true;
        }
        $age = $nowUtc->getTimestamp() - $synced->getTimestamp();
        $local = $nowUtc->setTimezone($timezone);
        $weekday = (int) $local->format('N') <= 5;
        $minutes = ((int) $local->format('G')) * 60 + (int) $local->format('i');
        $open = (8 * 60) + 30;
        $afterClose = 16 * 60;
        if ($weekday && $minutes >= $open && $minutes <= $afterClose) {
            return $age > 90 * 60;
        }

        return $age > 26 * 3600;
    }
}
