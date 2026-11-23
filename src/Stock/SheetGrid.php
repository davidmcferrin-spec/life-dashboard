<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns a Sheets values grid into rows.
 * Header names are the contract. Column letters are not.
 * A percent-formatted cell requested as a raw value is a fraction, so a
 * numeric percent is scaled to points. A display string such as "-3.41%"
 * already is points.
 */
final class SheetGrid
{
    /**
     * @param list<list<mixed>> $grid
     * @return list<array<string, mixed>>
     */
    public static function watchlist(array $grid): array
    {
        return self::quotes($grid, self::quoteAliases(), 'symbol', false);
    }

    /**
     * @param list<list<mixed>> $grid
     * @return list<array<string, mixed>>
     */
    public static function commodities(array $grid): array
    {
        return self::quotes($grid, self::quoteAliases(true), 'symbol', true);
    }

    /**
     * @param list<list<mixed>> $grid
     * @return list<array<string, mixed>>
     */
    public static function play(array $grid): array
    {
        if ($grid === []) {
            return [];
        }
        $header = array_shift($grid);
        if (!is_array($header)) {
            return [];
        }
        $index = self::index($header, [
            'date'   => ['date'],
            'ticker' => ['ticker'],
            'amount' => ['amount ($ invested)', 'amount'],
            'shares' => ['shares'],
            'price'  => ['price (per share at buy)', 'price'],
        ]);
        if (!isset($index['ticker'])) {
            throw SheetException::make('Play Account is missing a Ticker header.');
        }
        $out = [];
        $seen = [];
        $order = 0;
        foreach ($grid as $row) {
            if (!is_array($row)) {
                break;
            }
            $ticker = self::symbol(self::text(self::raw($row, $index['ticker'])));
            if ($ticker === null) {
                break;
            }
            $date = self::date(self::raw($row, $index['date'] ?? null));
            if ($date === null) {
                continue;
            }
            $order++;
            $seen[$date . '|' . $ticker] = [
                'date'       => $date,
                'ticker'     => $ticker,
                'amount'     => self::number(self::raw($row, $index['amount'] ?? null)),
                'shares'     => self::number(self::raw($row, $index['shares'] ?? null)),
                'price'      => self::number(self::raw($row, $index['price'] ?? null)),
                'sort_order' => $order,
            ];
        }

        return array_values($seen);
    }

    public static function number(mixed $value, bool $percent = false): ?float
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            if (!is_finite((float) $value)) {
                return null;
            }
            $number = (float) $value;

            return $percent ? $number * 100 : $number;
        }
        $text = trim((string) $value);
        if ($text === '' || $text === '-' || $text === '—') {
            return null;
        }
        $lower = strtolower($text);
        if (in_array($lower, ['n/a', 'na', '#n/a', '#value!', '#div/0!', 'null'], true)) {
            return null;
        }
        $negative = str_starts_with($text, '(') && str_ends_with($text, ')');
        $text = str_replace([',', '$', '%', ' ', "\u{00A0}"], '', $text);
        $text = trim($text, '()');
        if ($negative && $text !== '' && !str_starts_with($text, '-')) {
            $text = '-' . $text;
        }
        if (!is_numeric($text)) {
            return null;
        }

        return (float) $text;
    }

    /**
     * History is one formula in a long table. A blank symbol in the middle is a
     * reload gap, so parsing continues. Null means the tab was empty or unread.
     * An empty list means the headers were there and no bar had a symbol and a date.
     *
     * @param list<list<mixed>> $grid
     * @return list<array<string, mixed>>|null
     */
    public static function history(array $grid): ?array
    {
        if ($grid === []) {
            return null;
        }
        $header = array_shift($grid);
        if (!is_array($header)) {
            return null;
        }
        $index = self::index($header, [
            'symbol' => ['symbol', 'ticker'],
            'date'   => ['date'],
            'open'   => ['open'],
            'high'   => ['high'],
            'low'    => ['low'],
            'close'  => ['close'],
            'volume' => ['volume'],
        ]);
        if (!isset($index['symbol'], $index['date'], $index['close'])) {
            throw SheetException::make('History is missing Symbol, Date, or Close.');
        }
        $out = [];
        foreach ($grid as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = self::symbol(self::text(self::raw($row, $index['symbol'])));
            if ($symbol === null) {
                continue;
            }
            $date = self::date(self::raw($row, $index['date']));
            if ($date === null) {
                continue;
            }
            $out[] = [
                'symbol' => $symbol,
                'date'   => $date,
                'open'   => self::number(self::raw($row, $index['open'] ?? null)),
                'high'   => self::number(self::raw($row, $index['high'] ?? null)),
                'low'    => self::number(self::raw($row, $index['low'] ?? null)),
                'close'  => self::number(self::raw($row, $index['close'])),
                'volume' => self::number(self::raw($row, $index['volume'] ?? null)),
            ];
        }

        return $out;
    }

    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)) && !str_contains((string) $value, '-'))) {
            $serial = (float) $value;
            if ($serial < 1) {
                return null;
            }
            $unix = (int) round(($serial - 25569) * 86400);

            return gmdate('Y-m-d', $unix);
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        foreach (['Y-m-d', 'n/j/Y', 'm/d/Y', 'M j, Y', 'F j, Y'] as $format) {
            $parsed = DateTimeImmutable::createFromFormat('!' . $format, $text, new DateTimeZone('UTC'));
            $errors = DateTimeImmutable::getLastErrors();
            if ($parsed instanceof DateTimeImmutable && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $parsed->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @param list<string> $aliases
     * @param list<mixed> $header
     * @param array<string, list<string>> $wanted
     * @return array<string, int>
     */
    private static function index(array $header, array $wanted): array
    {
        $found = [];
        foreach ($header as $position => $cell) {
            if (!is_string($cell) && !is_numeric($cell)) {
                continue;
            }
            $name = self::norm((string) $cell);
            if ($name !== '' && !array_key_exists($name, $found)) {
                $found[$name] = (int) $position;
            }
        }
        $index = [];
        foreach ($wanted as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (array_key_exists($alias, $found)) {
                    $index[$field] = $found[$alias];
                    break;
                }
            }
        }

        return $index;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function quoteAliases(bool $commodity = false): array
    {
        $aliases = [
            'role'               => ['role'],
            'exchange'           => ['exchange'],
            'symbol'             => ['symbol'],
            'name'               => ['name'],
            'price'              => ['price'],
            'open'               => ['open'],
            'high'               => ['high'],
            'low'                => ['low'],
            'change'             => ['change ($ vs previous close)', 'change'],
            'change_pct'         => ['change %'],
            'prev_close'         => ['prev close'],
            'high_52w'           => ['52-week high'],
            'low_52w'            => ['52-week low'],
            'pct_off_52w'        => ['% off 52-week high'],
            'volume'             => ['volume'],
            'avg_volume'         => ['avg volume'],
            'market_cap'         => ['market cap'],
            'pe'                 => ['p/e'],
            'eps'                => ['eps'],
            'beta'               => ['beta'],
            'shares_outstanding' => ['shares outstanding'],
            'currency'           => ['currency'],
            'thesis'             => ["why i'm tracking / thesis"],
            'last_reviewed'      => ['last reviewed'],
        ];
        if ($commodity) {
            $aliases = ['proxy_for' => ['proxy-for', 'proxy for']] + $aliases;
        }

        return $aliases;
    }

    /**
     * @param list<list<mixed>> $grid
     * @param array<string, list<string>> $aliases
     * @return list<array<string, mixed>>
     */
    private static function quotes(array $grid, array $aliases, string $key, bool $commodity): array
    {
        if ($grid === []) {
            return [];
        }
        $header = array_shift($grid);
        if (!is_array($header)) {
            return [];
        }
        $index = self::index($header, $aliases);
        if (!isset($index[$key])) {
            throw SheetException::make('The sheet is missing a Symbol header.');
        }
        $seen = [];
        $order = 0;
        foreach ($grid as $row) {
            if (!is_array($row)) {
                break;
            }
            $symbol = self::symbol(self::text(self::raw($row, $index[$key])));
            if ($symbol === null) {
                break;
            }
            $order++;
            $parsed = [
                'role'               => self::clip(self::text(self::raw($row, $index['role'] ?? null)), 40),
                'exchange'           => self::clip(self::text(self::raw($row, $index['exchange'] ?? null)), 40),
                'symbol'             => $symbol,
                'name'               => self::clip(self::text(self::raw($row, $index['name'] ?? null)), 200),
                'proxy_for'          => $commodity ? self::clip(self::text(self::raw($row, $index['proxy_for'] ?? null)), 120) : null,
                'price'              => self::number(self::raw($row, $index['price'] ?? null)),
                'open'               => self::number(self::raw($row, $index['open'] ?? null)),
                'high'               => self::number(self::raw($row, $index['high'] ?? null)),
                'low'                => self::number(self::raw($row, $index['low'] ?? null)),
                'change'             => self::number(self::raw($row, $index['change'] ?? null)),
                'change_pct'         => self::number(self::raw($row, $index['change_pct'] ?? null), true),
                'prev_close'         => self::number(self::raw($row, $index['prev_close'] ?? null)),
                'high_52w'           => self::number(self::raw($row, $index['high_52w'] ?? null)),
                'low_52w'            => self::number(self::raw($row, $index['low_52w'] ?? null)),
                'pct_off_52w'        => self::number(self::raw($row, $index['pct_off_52w'] ?? null), true),
                'volume'             => self::number(self::raw($row, $index['volume'] ?? null)),
                'avg_volume'         => self::number(self::raw($row, $index['avg_volume'] ?? null)),
                'market_cap'         => self::number(self::raw($row, $index['market_cap'] ?? null)),
                'pe'                 => self::number(self::raw($row, $index['pe'] ?? null)),
                'eps'                => self::number(self::raw($row, $index['eps'] ?? null)),
                'beta'               => self::number(self::raw($row, $index['beta'] ?? null)),
                'shares_outstanding' => self::number(self::raw($row, $index['shares_outstanding'] ?? null)),
                'currency'           => self::currency(self::text(self::raw($row, $index['currency'] ?? null))),
                'thesis'             => self::clip(self::text(self::raw($row, $index['thesis'] ?? null)), 4000),
                'last_reviewed'      => self::date(self::raw($row, $index['last_reviewed'] ?? null)),
                'sort_order'         => $order,
            ];
            $seen[$symbol] = $parsed;
        }

        return array_values($seen);
    }

    private static function raw(array $row, ?int $index): mixed
    {
        if ($index === null || !array_key_exists($index, $row)) {
            return null;
        }
        $value = $row[$index];
        if (is_string($value) && trim($value) === '') {
            return null;
        }

        return $value;
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || is_bool($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            $text = rtrim(rtrim(sprintf('%.10F', (float) $value), '0'), '.');

            return $text === '' ? null : $text;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private static function symbol(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $symbol = strtoupper(trim($value));
        if ($symbol === '') {
            return null;
        }

        return mb_substr($symbol, 0, 32);
    }

    private static function currency(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $currency = strtoupper(trim($value));

        return $currency === '' ? null : mb_substr($currency, 0, 12);
    }

    private static function clip(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $max);
    }

    private static function norm(string $header): string
    {
        $header = str_replace(["\u{2019}", "\u{2018}"], "'", $header);
        $header = strtolower(trim($header));

        return preg_replace('/\s+/u', ' ', $header) ?? $header;
    }
}
