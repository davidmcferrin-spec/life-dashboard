<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

use Dashboard\Support\ValidationException;

/** Shared parsing for vault list filters. Dates are UTC. */
final class PageQuery
{
    public static function since(string $raw): string
    {
        $raw = trim($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) !== 1
            && preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $raw) !== 1) {
            throw ValidationException::withFields(['updated_since' => 'updated_since must be YYYY-MM-DD or a timestamp.']);
        }
        $raw = str_replace('T', ' ', $raw);
        if (strlen($raw) === 10) {
            $raw .= ' 00:00:00';
        } elseif (strlen($raw) === 16) {
            $raw .= ':00';
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $raw, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if ($dt === false || ($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            throw ValidationException::withFields(['updated_since' => 'updated_since must be YYYY-MM-DD or a timestamp.']);
        }
        return $dt->format('Y-m-d H:i:s');
    }
}
