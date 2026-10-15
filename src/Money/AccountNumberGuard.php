<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\Support\ValidationException;

/**
 * Full account numbers are never stored. Last 4 is the only mask we keep.
 * The creator-subscription name is replaced in memory and never written.
 */
final class AccountNumberGuard
{
    public const CREATOR = 'Creator subscription';

    public static function assertNoFullNumber(string $value, string $field = 'value'): void
    {
        if (self::hasFullNumber($value)) {
            throw ValidationException::withFields([
                $field => 'Full account numbers are not stored. Use the last 4 digits only.',
            ]);
        }
    }

    public static function hasFullNumber(string $value): bool
    {
        $text = trim($value);
        if ($text === '' || self::isLast4Mask($text)) {
            return false;
        }
        $stripped = self::stripSafeTokens($text);
        return preg_match('/\d{8,}/', $stripped) === 1;
    }

    /**
     * Four digits, or a mask such as ****1234. Anything else is refused.
     */
    public static function last4(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (preg_match('/^[*xX•·.\-\s]*(\d{4})$/u', $text, $m) === 1) {
            return $m[1];
        }
        throw ValidationException::withFields([
            'last4' => 'Last 4 must be four digits, or a mask like ****1234.',
        ]);
    }

    /**
     * Replace the secret payee wherever it appears. The secret is not returned.
     */
    public static function alias(string $value, string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '' || $value === '') {
            return $value;
        }
        $replaced = preg_replace('/' . preg_quote($secret, '/') . '/iu', self::CREATOR, $value);
        return $replaced === null ? $value : $replaced;
    }

    public static function redact(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }
        $safe = self::stripSafeTokens($value);
        if (preg_match('/\d{8,}/', $safe) !== 1) {
            return $value;
        }
        $redacted = preg_replace('/\d{8,}/', '[redacted]', $value);
        return $redacted === null ? $value : $redacted;
    }

    private static function isLast4Mask(string $text): bool
    {
        return preg_match('/^[*xX•·.\-\s]*\d{4}$/u', $text) === 1;
    }

    /**
     * Dates and money amounts are not account numbers. Remove them before the digit scan.
     */
    private static function stripSafeTokens(string $value): string
    {
        $value = preg_replace('/\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?/', ' ', $value) ?? $value;
        $value = preg_replace('/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/', ' ', $value) ?? $value;
        $value = preg_replace('/[$]?\d{1,3}(?:,\d{3})+(?:\.\d+)?/', ' ', $value) ?? $value;
        $value = preg_replace('/[$]?\d+\.\d+/', ' ', $value) ?? $value;
        $value = preg_replace('/[*xX•·]{2,}\d{4}/u', ' ', $value) ?? $value;
        return $value;
    }
}
