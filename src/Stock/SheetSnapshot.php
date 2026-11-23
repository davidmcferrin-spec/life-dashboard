<?php

declare(strict_types=1);

namespace Dashboard\Stock;

/**
 * Benchmarks and the largest movers from stored quotes.
 * Play-account buys are not included. There is no portfolio result here.
 */
final class SheetSnapshot
{
    /**
     * @param list<array<string, mixed>> $watchlist
     * @param list<array<string, mixed>> $commodities
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public static function from(array $watchlist, array $commodities, array $status): array
    {
        $benchmarks = [];
        $candidates = [];
        $seenBench = [];
        foreach ([$watchlist, $commodities] as $rows) {
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
                if ($symbol === '') {
                    continue;
                }
                if (strcasecmp(trim((string) ($row['role'] ?? '')), 'benchmark') === 0) {
                    if (isset($seenBench[$symbol])) {
                        continue;
                    }
                    $seenBench[$symbol] = true;
                    $benchmarks[] = self::quote($row);
                    continue;
                }
                $candidates[] = $row;
            }
        }
        usort($candidates, static function (array $a, array $b): int {
            return abs((float) ($b['change_pct'] ?? 0)) <=> abs((float) ($a['change_pct'] ?? 0));
        });
        $movers = [];
        $seen = $seenBench;
        foreach ($candidates as $row) {
            if (!isset($row['change_pct']) || $row['change_pct'] === null || abs((float) $row['change_pct']) < 0.01) {
                continue;
            }
            $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
            if ($symbol === '' || isset($seen[$symbol])) {
                continue;
            }
            $seen[$symbol] = true;
            $movers[] = self::quote($row);
            if (count($movers) >= 3) {
                break;
            }
        }

        return [
            'configured'     => !empty($status['configured']),
            'stale'          => !empty($status['stale']),
            'last_synced_at' => $status['last_synced_at'] ?? null,
            'benchmarks'     => array_slice($benchmarks, 0, 4),
            'movers'         => $movers,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{symbol: string, price: float|null, change_pct: float|null}
     */
    private static function quote(array $row): array
    {
        return [
            'symbol'     => strtoupper(trim((string) ($row['symbol'] ?? ''))),
            'price'      => isset($row['price']) && $row['price'] !== null && $row['price'] !== '' ? (float) $row['price'] : null,
            'change_pct' => isset($row['change_pct']) && $row['change_pct'] !== null && $row['change_pct'] !== '' ? (float) $row['change_pct'] : null,
        ];
    }
}
