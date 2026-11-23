<?php

declare(strict_types=1);

namespace Dashboard\Stock;

/**
 * The stocks list when a sheet is connected.
 * The page gets every quote. The home widget stays a short benchmark list.
 */
final class SheetStockList
{
    public const CAP = 500;

    /**
     * @param list<array<string, mixed>> $watchlist
     * @param list<array<string, mixed>> $commodities
     * @param list<array<string, mixed>> $play
     * @return array<string, mixed>
     */
    public static function build(array $watchlist, array $commodities, array $play, ?string $refreshed, int $widgetMax): array
    {
        $symbols = [];
        foreach ($watchlist as $row) {
            $symbols[] = self::quote($row, 'watchlist', $refreshed);
        }
        foreach ($commodities as $row) {
            $symbols[] = self::quote($row, 'commodity', $refreshed);
        }

        return [
            'source'             => 'sheet',
            'symbols'            => $symbols,
            'widget_symbols'     => self::widget($watchlist, $commodities, $refreshed, $widgetMax),
            'max_symbols'        => max(count($symbols), self::CAP),
            'watchlist'          => array_values($watchlist),
            'commodities'        => array_values($commodities),
            'play_account'       => array_values($play),
        ];
    }

    /**
     * @param list<array<string, mixed>> $watchlist
     * @param list<array<string, mixed>> $commodities
     * @return list<array<string, mixed>>
     */
    public static function widget(array $watchlist, array $commodities, ?string $refreshed, int $limit): array
    {
        $limit = max(1, $limit);
        $benchmarks = [];
        foreach ([['watchlist', $watchlist], ['commodity', $commodities]] as [$group, $rows]) {
            foreach ($rows as $row) {
                if (!is_array($row) || !self::benchmark($row)) {
                    continue;
                }
                $benchmarks[] = self::quote($row, (string) $group, $refreshed);
            }
        }
        if ($benchmarks !== []) {
            return array_slice($benchmarks, 0, $limit);
        }
        $fallback = [];
        foreach ($watchlist as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fallback[] = self::quote($row, 'watchlist', $refreshed);
            if (count($fallback) >= $limit) {
                break;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function quote(array $row, string $group, ?string $refreshed): array
    {
        $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));

        return [
            'id'               => isset($row['id']) ? (int) $row['id'] : 0,
            'symbol'           => $symbol,
            'name'             => self::text($row['name'] ?? null),
            'role'             => self::text($row['role'] ?? null),
            'group'            => $group,
            'is_active'        => true,
            'pin_to_dashboard' => self::benchmark($row),
            'price'            => self::num($row['price'] ?? null),
            'change_amount'    => self::num($row['change'] ?? null),
            'change_percent'   => self::num($row['change_pct'] ?? null),
            'last_refreshed'   => $refreshed,
            'source'           => 'sheet',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function benchmark(array $row): bool
    {
        return strcasecmp(trim((string) ($row['role'] ?? '')), 'benchmark') === 0;
    }

    private static function num(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
