<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

use DateTimeImmutable;

/**
 * Use-by arithmetic. Dates are Y-m-d.
 *
 * A null shelf life leaves the current date alone. Zero days is a real life
 * and means the use-by date is the start date.
 */
final class PantryDates
{
    public const LOCATIONS = ['pantry', 'fridge', 'freezer'];

    public const LEVELS = ['full', 'most', 'half', 'low', 'nearly_empty'];

    public static function addDays(string $date, int $days): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            throw new \InvalidArgumentException('Date must be YYYY-MM-DD.');
        }
        $sign = $days >= 0 ? '+' : '';
        return $parsed->modify($sign . $days . ' days')->format('Y-m-d');
    }

    public static function daysFor(string $location, ?int $productDays, ?int $categoryDays): ?int
    {
        if (!in_array($location, self::LOCATIONS, true)) {
            throw new \InvalidArgumentException('Unknown location.');
        }
        return $productDays ?? $categoryDays;
    }

    public static function initialUseBy(string $purchasedAt, ?int $days): ?string
    {
        if ($days === null) {
            return null;
        }
        return self::addDays($purchasedAt, $days);
    }

    /**
     * Freezer life starts the day the lot moves in. Fridge and pantry life
     * still starts on the purchase date. An override is kept unless reset.
     *
     * @return array{use_by: ?string, overridden: bool}
     */
    public static function afterMove(
        string $location,
        string $purchasedAt,
        string $movedOn,
        ?string $currentUseBy,
        bool $overridden,
        bool $reset,
        ?int $days,
    ): array {
        if ($overridden && !$reset) {
            return ['use_by' => $currentUseBy, 'overridden' => true];
        }
        if ($days === null) {
            return ['use_by' => $currentUseBy, 'overridden' => false];
        }
        $from = $location === 'freezer' ? $movedOn : $purchasedAt;
        return ['use_by' => self::addDays($from, $days), 'overridden' => false];
    }

    /**
     * Opening tightens use-by to the earlier of the current date and
     * opened-on plus opened life. A date that does not move keeps its override.
     *
     * @return array{use_by: ?string, overridden: bool}
     */
    public static function afterOpen(?string $currentUseBy, string $openedOn, ?int $openedDays, bool $overridden): array
    {
        if ($openedDays === null) {
            return ['use_by' => $currentUseBy, 'overridden' => $overridden];
        }
        $openedBy = self::addDays($openedOn, $openedDays);
        if ($currentUseBy === null || $openedBy < $currentUseBy) {
            return ['use_by' => $openedBy, 'overridden' => false];
        }
        return ['use_by' => $currentUseBy, 'overridden' => $overridden];
    }
}
