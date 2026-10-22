<?php

declare(strict_types=1);

namespace Dashboard\Spotify;

/**
 * Shared with the Python sync client. Four attempts, then stop.
 * 429 honors Retry-After, capped at 60 seconds. A missing Retry-After and
 * every 5xx wait min(2^attempt, 30). 401, 403, and 404 are not retried.
 */
final class Retry
{
    public const MAX_ATTEMPTS = 4;

    public static function backoffSeconds(int $status, ?int $retryAfter, int $attempt): ?int
    {
        if ($attempt >= self::MAX_ATTEMPTS) {
            return null;
        }
        if ($status === 429) {
            if ($retryAfter !== null && $retryAfter >= 0) {
                return min($retryAfter, 60);
            }
            return min(2 ** $attempt, 30);
        }
        if ($status >= 500 && $status <= 599) {
            return min(2 ** $attempt, 30);
        }
        return null;
    }

    /** Refresh when expiry is missing or within $skew seconds. */
    public static function needsRefresh(?string $expiresAt, int $now, int $skew = 60): bool
    {
        if ($expiresAt === null || trim($expiresAt) === '') {
            return true;
        }
        $ts = strtotime(trim($expiresAt) . ' UTC');
        if ($ts === false) {
            return true;
        }
        return $ts <= $now + $skew;
    }
}
