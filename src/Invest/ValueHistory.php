<?php

declare(strict_types=1);

namespace Dashboard\Invest;

/**
 * Display stats from stored snapshots and stored position prices.
 * A missing input stays null so the page can show a dash.
 */
final class ValueHistory
{
    /**
     * @param list<array{market_value: float|null}> $positions
     */
    public static function portfolioValue(array $positions): ?float
    {
        if ($positions === []) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($positions as $position) {
            if (!array_key_exists('market_value', $position) || $position['market_value'] === null) {
                return null;
            }
            $sum += (float) $position['market_value'];
        }
        return $sum;
    }

    public static function positionValue(?float $mark, float $quantity, bool $priceKnown): ?float
    {
        if (!$priceKnown || $mark === null) {
            return null;
        }
        return $mark * $quantity;
    }

    public static function unrealized(?float $mark, ?float $avg, float $quantity, bool $priceKnown, bool $costKnown): ?float
    {
        if (!$priceKnown || !$costKnown || $mark === null || $avg === null) {
            return null;
        }
        return ($mark - $avg) * $quantity;
    }

    /**
     * (mark - avg) * quantity / abs(avg * quantity). Null when cost is unknown or zero.
     */
    public static function positionReturn(?float $mark, ?float $avg, float $quantity, bool $priceKnown, bool $costKnown): ?float
    {
        $gain = self::unrealized($mark, $avg, $quantity, $priceKnown, $costKnown);
        if ($gain === null || $avg === null) {
            return null;
        }
        $cost = $avg * $quantity;
        if ($cost == 0.0) {
            return null;
        }
        return $gain / abs($cost);
    }

    /**
     * @param list<array{as_of: string, market_value: float|null, cash?: float|null}> $rows
     * @return array{
     *     day_change: float|null,
     *     day_change_ratio: float|null,
     *     buying_power: float|null,
     *     annual_return: float|null,
     *     series: list<array{date: string, market_value: float}>
     * }
     */
    public static function metrics(array $rows, string $asOf): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $date = substr(trim((string) ($row['as_of'] ?? '')), 0, 10);
            if (!self::isDate($date)) {
                continue;
            }
            $market = $row['market_value'] ?? null;
            $cash = $row['cash'] ?? null;
            $byDate[$date] = [
                'market_value' => $market === null || $market === '' ? null : (float) $market,
                'cash'         => $cash === null || $cash === '' ? null : (float) $cash,
            ];
        }

        $today = $byDate[$asOf] ?? null;
        $priorDate = self::shift($asOf, '-1 day');
        $prior = $priorDate !== null ? ($byDate[$priorDate] ?? null) : null;
        $yearDate = self::shift($asOf, '-1 year');
        $year = $yearDate !== null ? ($byDate[$yearDate] ?? null) : null;

        $dayChange = null;
        $dayRatio = null;
        $todayValue = $today['market_value'] ?? null;
        $priorValue = $prior['market_value'] ?? null;
        if ($todayValue !== null && $priorValue !== null) {
            $dayChange = $todayValue - $priorValue;
            if ($priorValue != 0.0) {
                $dayRatio = $dayChange / abs($priorValue);
            }
        }

        $annual = null;
        $yearValue = $year['market_value'] ?? null;
        if ($todayValue !== null && $yearValue !== null && $yearValue != 0.0) {
            $annual = ($todayValue - $yearValue) / abs($yearValue);
        }

        $start = self::shift($asOf, '-6 months');
        $series = [];
        if ($start !== null) {
            ksort($byDate);
            foreach ($byDate as $date => $point) {
                if ($point['market_value'] === null || $date < $start || $date > $asOf) {
                    continue;
                }
                $series[] = [
                    'date'         => $date,
                    'market_value' => $point['market_value'],
                ];
            }
        }

        return [
            'day_change'       => $dayChange,
            'day_change_ratio' => $dayRatio,
            'buying_power'     => $today['cash'] ?? null,
            'annual_return'    => $annual,
            'series'           => $series,
        ];
    }

    public static function shift(string $date, string $modifier): ?string
    {
        if (!self::isDate($date)) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        if (!$dt instanceof \DateTimeImmutable) {
            return null;
        }
        return $dt->modify($modifier)->format('Y-m-d');
    }

    private static function isDate(string $date): bool
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        return $dt instanceof \DateTimeImmutable && $dt->format('Y-m-d') === $date;
    }
}
