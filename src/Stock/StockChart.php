<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Day is the hourly price from each sheet sync. Week, month, and year are daily closes.
 * Windows are applied when the chart is read. Stored bars older than a year stay stored.
 */
final class StockChart
{
    /** @var list<string> */
    public const RANGES = ['day', 'week', 'month', 'year'];

    public static function symbol(string $value): string
    {
        $symbol = strtoupper(trim($value));
        if ($symbol === '' || preg_match('/^[A-Z0-9][A-Z0-9.\-]{0,31}$/', $symbol) !== 1) {
            throw ValidationException::withFields(['symbol' => 'Enter a symbol.']);
        }

        return $symbol;
    }

    public static function range(string $value): string
    {
        $range = strtolower(trim($value));
        if (!in_array($range, self::RANGES, true)) {
            throw ValidationException::withFields(['range' => 'Range must be day, week, month, or year.']);
        }

        return $range;
    }

    /**
     * A blank incoming price keeps the stored one. An all-blank read keeps the stored bar
     * and does not create a row.
     *
     * @param array<string, mixed>|null $stored
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>|null
     */
    public static function keep(?array $stored, array $incoming): ?array
    {
        $fields = ['open', 'high', 'low', 'close', 'volume'];
        $any = false;
        foreach ($fields as $field) {
            if (self::num($incoming[$field] ?? null) !== null) {
                $any = true;
                break;
            }
        }
        if (!$any) {
            return $stored;
        }
        $merged = $incoming;
        foreach ($fields as $field) {
            $value = self::num($incoming[$field] ?? null);
            if ($value === null && $stored !== null) {
                $value = self::num($stored[$field] ?? null);
            }
            $merged[$field] = $value;
        }

        return $merged;
    }

    /**
     * @param list<array<string, mixed>> $bars
     * @param list<array<string, mixed>> $ticks
     * @return array{symbol: string, ranges: array<string, array{grain: string, points: list<array<string, mixed>>}>}
     */
    public static function series(string $symbol, array $bars, array $ticks, DateTimeZone $tz): array
    {
        $daily = self::closes($bars);
        $end = $daily === [] ? null : $daily[array_key_last($daily)]['date'];

        return [
            'symbol' => $symbol,
            'ranges' => [
                'day'   => ['grain' => 'hour', 'points' => self::day($ticks, $tz)],
                'week'  => ['grain' => 'day', 'points' => self::since($daily, $end, '6 days')],
                'month' => ['grain' => 'day', 'points' => self::since($daily, $end, '1 month')],
                'year'  => ['grain' => 'day', 'points' => self::since($daily, $end, '1 year')],
            ],
        ];
    }

    /**
     * @param array{symbol: string, ranges: array<string, array{grain: string, points: list<array<string, mixed>>}>} $series
     * @return array<string, mixed>
     */
    public static function pick(array $series, ?string $range): array
    {
        if ($range === null || trim($range) === '') {
            return $series;
        }
        $name = self::range($range);
        $series['range'] = $name;
        $series['ranges'] = [$name => $series['ranges'][$name]];

        return $series;
    }

    /**
     * @param list<array<string, mixed>> $bars
     * @return list<array{date: string, open: ?float, high: ?float, low: ?float, close: float, volume: ?float}>
     */
    private static function closes(array $bars): array
    {
        $byDate = [];
        foreach ($bars as $bar) {
            if (!is_array($bar)) {
                continue;
            }
            $date = (string) ($bar['date'] ?? '');
            $close = self::num($bar['close'] ?? null);
            if ($date === '' || $close === null) {
                continue;
            }
            $byDate[$date] = [
                'date'   => $date,
                'open'   => self::num($bar['open'] ?? null),
                'high'   => self::num($bar['high'] ?? null),
                'low'    => self::num($bar['low'] ?? null),
                'close'  => $close,
                'volume' => self::num($bar['volume'] ?? null),
            ];
        }
        ksort($byDate);

        return array_values($byDate);
    }

    /**
     * The latest Chicago session that has an hourly price. Earlier days stay stored.
     *
     * @param list<array<string, mixed>> $ticks
     * @return list<array{at: string, label: string, price: float}>
     */
    private static function day(array $ticks, DateTimeZone $tz): array
    {
        $parsed = [];
        foreach ($ticks as $tick) {
            if (!is_array($tick)) {
                continue;
            }
            $price = self::num($tick['price'] ?? null);
            $at = (string) ($tick['at'] ?? '');
            $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $at, new DateTimeZone('UTC'));
            if ($price === null || !$moment instanceof DateTimeImmutable) {
                continue;
            }
            $parsed[] = ['moment' => $moment, 'at' => $moment->format('Y-m-d H:i:s'), 'price' => $price];
        }
        if ($parsed === []) {
            return [];
        }
        usort($parsed, static fn (array $a, array $b): int => $a['moment'] <=> $b['moment']);
        $session = $parsed[array_key_last($parsed)]['moment']->setTimezone($tz)->format('Y-m-d');
        $out = [];
        foreach ($parsed as $tick) {
            $local = $tick['moment']->setTimezone($tz);
            if ($local->format('Y-m-d') !== $session) {
                continue;
            }
            $out[] = [
                'at'    => $tick['at'],
                'label' => $local->format('H:i'),
                'price' => $tick['price'],
            ];
        }

        return $out;
    }

    /**
     * @param list<array{date: string, open: ?float, high: ?float, low: ?float, close: float, volume: ?float}> $points
     * @return list<array{date: string, open: ?float, high: ?float, low: ?float, close: float, volume: ?float}>
     */
    private static function since(array $points, ?string $end, string $back): array
    {
        if ($end === null || $points === []) {
            return [];
        }
        $start = (new DateTimeImmutable($end . ' 00:00:00', new DateTimeZone('UTC')))
            ->modify('-' . $back)
            ->format('Y-m-d');
        $out = [];
        foreach ($points as $point) {
            if ($point['date'] >= $start && $point['date'] <= $end) {
                $out[] = $point;
            }
        }

        return $out;
    }

    private static function num(mixed $value): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || !is_numeric($value)) {
            return null;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return null;
        }

        return $number;
    }
}
