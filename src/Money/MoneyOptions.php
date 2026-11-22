<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\DB\Database;
use Dashboard\Support\ValidationException;

/**
 * Per-user money numbers. They live in the database so they are not
 * environment variables. They are not secrets, so the settings form shows them.
 */
final class MoneyOptions
{
    public const DEFAULT_STALE_HOURS = 24;

    public const DEFAULT_GROCERIES = 150.0;

    public const DEFAULT_EATING_OUT = 125.0;

    /**
     * @return array{stale_hours: int, groceries_weekly: float, eating_out_weekly: float}
     */
    public static function read(Database $db, int $userId): array
    {
        $row = $db->fetchOne(
            'SELECT stale_hours, groceries_weekly, eating_out_weekly FROM money_user_options WHERE user_id = ?',
            [$userId]
        );
        if ($row === null) {
            return self::defaults();
        }

        return [
            'stale_hours'        => (int) $row['stale_hours'],
            'groceries_weekly'   => (float) $row['groceries_weekly'],
            'eating_out_weekly'  => (float) $row['eating_out_weekly'],
        ];
    }

    /**
     * @return array{stale_hours: int, groceries_weekly: float, eating_out_weekly: float}
     */
    public static function defaults(): array
    {
        return [
            'stale_hours'       => self::DEFAULT_STALE_HOURS,
            'groceries_weekly'  => self::DEFAULT_GROCERIES,
            'eating_out_weekly' => self::DEFAULT_EATING_OUT,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{stale_hours: int, groceries_weekly: float, eating_out_weekly: float}
     */
    public static function save(Database $db, int $userId, array $input): array
    {
        $current = self::read($db, $userId);
        $hours = array_key_exists('stale_hours', $input) ? self::hours($input['stale_hours']) : $current['stale_hours'];
        $groceries = array_key_exists('groceries_weekly', $input)
            ? self::money($input['groceries_weekly'], 'groceries_weekly')
            : $current['groceries_weekly'];
        $eating = array_key_exists('eating_out_weekly', $input)
            ? self::money($input['eating_out_weekly'], 'eating_out_weekly')
            : $current['eating_out_weekly'];
        $db->execute(
            'INSERT INTO money_user_options (user_id, stale_hours, groceries_weekly, eating_out_weekly)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                stale_hours = EXCLUDED.stale_hours,
                groceries_weekly = EXCLUDED.groceries_weekly,
                eating_out_weekly = EXCLUDED.eating_out_weekly,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $hours, $groceries, $eating]
        );

        return self::read($db, $userId);
    }

    private static function hours(mixed $value): int
    {
        if (!is_numeric($value) || (int) $value != (float) $value) {
            throw ValidationException::withFields(['stale_hours' => 'Stale hours must be a whole number.']);
        }
        $hours = (int) $value;
        if ($hours < 1 || $hours > 8760) {
            throw ValidationException::withFields(['stale_hours' => 'Stale hours must be between 1 and 8760.']);
        }

        return $hours;
    }

    private static function money(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields([$field => 'Enter a number.']);
        }
        $amount = round((float) $value, 2);
        if ($amount < 0 || $amount > 1000000) {
            throw ValidationException::withFields([$field => 'Use an amount from 0 to 1000000.']);
        }

        return $amount;
    }
}
