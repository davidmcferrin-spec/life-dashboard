<?php

declare(strict_types=1);

namespace Dashboard\Money;

/**
 * Food weeks run Sunday through Saturday. Groceries and eating out are
 * separate from the older single Food cap.
 */
final class FoodWeek
{
    /** @var list<string> */
    private const GROCERIES = ['grocery', 'groceries'];

    /** @var list<string> */
    private const EATING_OUT = [
        'eating out', 'dining', 'dining out', 'restaurant', 'restaurants',
        'fast food', 'takeout', 'take-out',
    ];

    public static function bucket(string $category): ?string
    {
        $name = strtolower(trim($category));
        if ($name === '') {
            return null;
        }
        if (in_array($name, self::GROCERIES, true) || str_contains($name, 'grocer')) {
            return 'groceries';
        }
        if (in_array($name, self::EATING_OUT, true) || str_contains($name, 'eating out') || str_contains($name, 'restaurant') || str_contains($name, 'dining')) {
            return 'eating_out';
        }

        return null;
    }

    /**
     * Sunday and Saturday of the week that contains this date.
     *
     * @return array{0: string, 1: string}
     */
    public static function weekContaining(string $date): array
    {
        $day = new \DateTimeImmutable($date);
        $start = $day->modify('-' . (int) $day->format('w') . ' days');

        return [$start->format('Y-m-d'), $start->modify('+6 days')->format('Y-m-d')];
    }

    /**
     * @param array{groceries: float, eating_out: float} $current
     * @param array{groceries: float, eating_out: float} $previous
     * @return array<string, mixed>
     */
    public static function report(
        string $start,
        string $end,
        string $previousStart,
        array $current,
        array $previous,
        float $groceryTarget,
        float $eatingTarget,
    ): array {
        $groceries = round($current['groceries'], 2);
        $eating = round($current['eating_out'], 2);
        $prevGroceries = round($previous['groceries'], 2);
        $prevEating = round($previous['eating_out'], 2);
        $total = round($groceries + $eating, 2);
        $prevTotal = round($prevGroceries + $prevEating, 2);
        $groceryTarget = round($groceryTarget, 2);
        $eatingTarget = round($eatingTarget, 2);
        $totalTarget = round($groceryTarget + $eatingTarget, 2);

        return [
            'week_start' => $start,
            'week_end'   => $end,
            'groceries'  => ['spent' => $groceries, 'target' => $groceryTarget, 'over' => $groceries > $groceryTarget],
            'eating_out' => ['spent' => $eating, 'target' => $eatingTarget, 'over' => $eating > $eatingTarget],
            'total'      => ['spent' => $total, 'target' => $totalTarget, 'over' => $total > $totalTarget],
            'two_week_average' => [
                'groceries'  => round(($groceries + $prevGroceries) / 2, 2),
                'eating_out' => round(($eating + $prevEating) / 2, 2),
                'total'      => round(($total + $prevTotal) / 2, 2),
            ],
            'previous_week' => [
                'week_start' => $previousStart,
                'groceries'  => $prevGroceries,
                'eating_out' => $prevEating,
                'total'      => $prevTotal,
            ],
        ];
    }
}
