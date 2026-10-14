<?php

declare(strict_types=1);

namespace Dashboard\Invest;

use Dashboard\Support\Csv;

/**
 * Robinhood account-activity CSV. Buys and sells rebuild positions.
 * This never talks to Robinhood and never places an order.
 */
final class RobinhoodCsv
{
    /**
     * @return list<array{symbol: ?string, kind: string, quantity: ?float, price: ?float, amount: ?float, occurred_on: string, note: ?string, dedupe_key: string}>
     */
    public static function rows(string $csv): array
    {
        $parsed = Csv::assocRows(self::fromHeader($csv));
        $out = [];
        foreach ($parsed as $row) {
            $date = self::date($row['activity date'] ?? $row['date'] ?? '');
            if ($date === null) {
                continue;
            }
            $kind = strtolower(trim($row['trans code'] ?? $row['type'] ?? 'other'));
            if ($kind === '') {
                $kind = 'other';
            }
            $symbol = strtoupper(trim($row['instrument'] ?? $row['symbol'] ?? ''));
            $symbol = $symbol !== '' ? mb_substr($symbol, 0, 80) : null;
            $qty = self::num($row['quantity'] ?? '');
            $price = self::num($row['price'] ?? '');
            $amount = self::num($row['amount'] ?? '');
            $note = trim($row['description'] ?? '');
            $note = $note !== '' ? mb_substr($note, 0, 240) : null;
            $dedupe = 'rh:' . substr(hash('sha256', json_encode([$date, $symbol, $kind, $qty, $price, $amount, $note], JSON_THROW_ON_ERROR)), 0, 40);
            $out[] = [
                'symbol'      => $symbol,
                'kind'        => mb_substr($kind, 0, 32),
                'quantity'    => $qty,
                'price'       => $price,
                'amount'      => $amount,
                'occurred_on' => $date,
                'note'        => $note,
                'dedupe_key'  => $dedupe,
            ];
        }
        return $out;
    }

    public static function isBuy(string $kind): bool
    {
        return str_contains($kind, 'buy');
    }

    public static function isSell(string $kind): bool
    {
        return str_contains($kind, 'sell');
    }

    private static function fromHeader(string $csv): string
    {
        $lines = preg_split("/\r\n|\n|\r/", $csv) ?: [];
        $start = 0;
        foreach ($lines as $i => $line) {
            $low = strtolower($line);
            if (str_contains($low, 'activity date') || str_contains($low, 'trans code')) {
                $start = $i;
                break;
            }
        }
        return implode("\n", array_slice($lines, $start));
    }

    private static function date(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }

    private static function num(string $raw): ?float
    {
        $raw = trim(str_replace([',', '$'], '', $raw));
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return (float) $raw;
    }
}
