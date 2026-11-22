<?php

declare(strict_types=1);

namespace Dashboard\Money;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A balance is stale when it was never confirmed, or the confirmation is
 * older than the user's threshold. The comparison uses UTC timestamps.
 */
final class MoneyFreshness
{
    /**
     * @return array{last_synced_at: ?string, stale: bool}
     */
    public static function describe(mixed $syncedAt, int $thresholdHours, DateTimeImmutable $nowUtc): array
    {
        $text = self::normalize($syncedAt);
        if ($text === null) {
            return ['last_synced_at' => null, 'stale' => true];
        }
        $synced = new DateTimeImmutable($text, new DateTimeZone('UTC'));
        $age = $nowUtc->setTimezone(new DateTimeZone('UTC'))->getTimestamp() - $synced->getTimestamp();
        $limit = max(1, $thresholdHours) * 3600;

        return [
            'last_synced_at' => $synced->format('Y-m-d H:i:s'),
            'stale'          => $age >= $limit,
        ];
    }

    private static function normalize(mixed $syncedAt): ?string
    {
        if ($syncedAt === null) {
            return null;
        }
        $text = trim((string) $syncedAt);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})/', $text, $m) === 1) {
            return $m[1] . ' ' . $m[2];
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $text) === 1) {
            return $text . ' 00:00:00';
        }

        return null;
    }
}
