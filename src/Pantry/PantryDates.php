<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

use DateTimeImmutable;

/**
 * Use-by arithmetic. Dates are Y-m-d.
 *
 * Zero days is a real life and means the use-by date is the start date.
 * A null life is left out of resolveUseBy; the caller keeps the current date
 * only when neither life is known.
 */
final class PantryDates
{
    public const LOCATIONS = ['pantry', 'fridge', 'freezer'];

    public const LEVELS = ['full', 'most', 'half', 'low', 'nearly_empty', 'empty'];

    /**
     * Condiment steps. most sits with full, nearly_empty sits with low.
     */
    public static function nextLevel(string $level): string
    {
        return match ($level) {
            'full', 'most' => 'half',
            'half' => 'low',
            'low', 'nearly_empty', 'empty' => 'empty',
            default => throw new \InvalidArgumentException('Unknown level.'),
        };
    }

    public static function stepsLeft(string $level): int
    {
        return match ($level) {
            'full', 'most' => 3,
            'half' => 2,
            'low', 'nearly_empty', 'empty' => 1,
            default => 0,
        };
    }

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
        return [
            'use_by' => self::resolveUseBy($location, $purchasedAt, $movedOn, null, $days, null),
            'overridden' => false,
        ];
    }

    /**
     * Shared use-by for add, open, reset, and a move.
     *
     * Opened life counts when the lot is open and that life is known.
     * Freezer life starts on $asOf (the day it is placed or moved). Fridge and
     * pantry life starts on the purchase date. When both dates exist, the
     * earlier one is the use-by. A stored date is not an input.
     */
    public static function resolveUseBy(
        string $location,
        string $purchasedAt,
        string $asOf,
        ?string $openedOn,
        ?int $locationDays,
        ?int $openedDays,
    ): ?string {
        if (!in_array($location, self::LOCATIONS, true)) {
            throw new \InvalidArgumentException('Unknown location.');
        }
        $unopened = null;
        if ($locationDays !== null) {
            $from = $location === 'freezer' ? $asOf : $purchasedAt;
            $unopened = self::addDays($from, $locationDays);
        }
        $opened = null;
        if ($openedOn !== null && $openedOn !== '' && $openedDays !== null) {
            $opened = self::addDays($openedOn, $openedDays);
        }
        if ($opened !== null && $unopened !== null) {
            return $opened < $unopened ? $opened : $unopened;
        }
        return $opened ?? $unopened;
    }
}
