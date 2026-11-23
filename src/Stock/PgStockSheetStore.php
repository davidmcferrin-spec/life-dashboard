<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use Dashboard\DB\Database;

/** Per-user sheet rows. Every statement is limited to that user. */
final class PgStockSheetStore implements StockSheetStore
{
    public function __construct(private readonly Database $db) {}

    public function watchlist(int $userId): array
    {
        return $this->quotes($userId, 'watchlist');
    }

    public function commodities(int $userId): array
    {
        return $this->quotes($userId, 'commodity');
    }

    public function play(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT trade_date, ticker, amount, shares, price
             FROM stock_play_trades WHERE user_id = ? ORDER BY sort_order ASC, trade_date ASC, ticker ASC',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'date'   => (string) $row['trade_date'],
                'ticker' => (string) $row['ticker'],
                'amount' => self::num($row['amount']),
                'shares' => self::num($row['shares']),
                'price'  => self::num($row['price']),
            ];
        }

        return $out;
    }

    public function status(int $userId): array
    {
        $row = $this->db->fetchOne('SELECT * FROM stock_sheet_sync WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return self::emptyStatus();
        }

        return [
            'status'          => (string) $row['status'],
            'sync_error'      => $row['sync_error'] !== null && $row['sync_error'] !== '' ? (string) $row['sync_error'] : null,
            'last_synced_at'  => $row['last_synced_at'] !== null ? (string) $row['last_synced_at'] : null,
            'last_attempt_at' => $row['last_attempt_at'] !== null ? (string) $row['last_attempt_at'] : null,
            'watchlist_count' => (int) $row['watchlist_count'],
            'commodity_count' => (int) $row['commodity_count'],
            'play_count'      => (int) $row['play_count'],
        ];
    }

    public function replace(int $userId, array $watchlist, array $commodities, array $play, array $status): void
    {
        $this->db->transaction(function () use ($userId, $watchlist, $commodities, $play, $status): void {
            $this->writeQuotes($userId, 'watchlist', $watchlist);
            $this->writeQuotes($userId, 'commodity', $commodities);
            $this->writePlay($userId, $play);
            $this->db->execute(
                'INSERT INTO stock_sheet_sync (user_id, status, sync_error, last_synced_at, last_attempt_at, watchlist_count, commodity_count, play_count)
                 VALUES (?, ?, NULL, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id) DO UPDATE SET
                    status = EXCLUDED.status,
                    sync_error = NULL,
                    last_synced_at = EXCLUDED.last_synced_at,
                    last_attempt_at = EXCLUDED.last_attempt_at,
                    watchlist_count = EXCLUDED.watchlist_count,
                    commodity_count = EXCLUDED.commodity_count,
                    play_count = EXCLUDED.play_count,
                    updated_at = (now() AT TIME ZONE \'UTC\')',
                [
                    $userId,
                    'ok',
                    (string) ($status['last_synced_at'] ?? null),
                    (string) ($status['last_attempt_at'] ?? $status['last_synced_at'] ?? null),
                    count($watchlist),
                    count($commodities),
                    count($play),
                ]
            );
        });
    }

    public function upsertBars(int $userId, array $bars): void
    {
        if ($bars === []) {
            return;
        }
        $this->db->transaction(function () use ($userId, $bars): void {
            foreach ($bars as $bar) {
                $symbol = strtoupper(trim((string) ($bar['symbol'] ?? '')));
                $date = (string) ($bar['date'] ?? '');
                if ($symbol === '' || $date === '') {
                    continue;
                }
                $open = self::num($bar['open'] ?? null);
                $high = self::num($bar['high'] ?? null);
                $low = self::num($bar['low'] ?? null);
                $close = self::num($bar['close'] ?? null);
                $volume = self::num($bar['volume'] ?? null);
                if ($open === null && $high === null && $low === null && $close === null && $volume === null) {
                    continue;
                }
                $this->db->execute(
                    'INSERT INTO stock_sheet_bars (user_id, symbol, bar_date, open_price, high_price, low_price, close_price, volume)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (user_id, symbol, bar_date) DO UPDATE SET
                        open_price = COALESCE(EXCLUDED.open_price, stock_sheet_bars.open_price),
                        high_price = COALESCE(EXCLUDED.high_price, stock_sheet_bars.high_price),
                        low_price = COALESCE(EXCLUDED.low_price, stock_sheet_bars.low_price),
                        close_price = COALESCE(EXCLUDED.close_price, stock_sheet_bars.close_price),
                        volume = COALESCE(EXCLUDED.volume, stock_sheet_bars.volume),
                        updated_at = (now() AT TIME ZONE \'UTC\')',
                    [$userId, $symbol, $date, $open, $high, $low, $close, $volume]
                );
            }
        });
    }

    public function snapshot(int $userId, array $quotes, string $capturedAt): void
    {
        $seen = [];
        $this->db->transaction(function () use ($userId, $quotes, $capturedAt, &$seen): void {
            foreach ($quotes as $row) {
                $symbol = strtoupper(trim((string) ($row['symbol'] ?? '')));
                $price = self::num($row['price'] ?? null);
                if ($symbol === '' || $price === null || isset($seen[$symbol])) {
                    continue;
                }
                $seen[$symbol] = true;
                $this->db->execute(
                    'INSERT INTO stock_sheet_ticks (user_id, symbol, captured_at, price)
                     VALUES (?, ?, ?, ?)
                     ON CONFLICT (user_id, symbol, captured_at) DO UPDATE SET
                        price = COALESCE(EXCLUDED.price, stock_sheet_ticks.price),
                        updated_at = (now() AT TIME ZONE \'UTC\')',
                    [$userId, $symbol, $capturedAt, $price]
                );
            }
        });
    }

    public function bars(int $userId, string $symbol): array
    {
        $rows = $this->db->fetchAll(
            'SELECT bar_date, open_price, high_price, low_price, close_price, volume
             FROM stock_sheet_bars WHERE user_id = ? AND symbol = ? ORDER BY bar_date ASC',
            [$userId, $symbol]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'date'   => (string) $row['bar_date'],
                'open'   => self::num($row['open_price']),
                'high'   => self::num($row['high_price']),
                'low'    => self::num($row['low_price']),
                'close'  => self::num($row['close_price']),
                'volume' => self::num($row['volume']),
            ];
        }

        return $out;
    }

    public function ticks(int $userId, string $symbol): array
    {
        $rows = $this->db->fetchAll(
            'SELECT captured_at, price FROM stock_sheet_ticks
             WHERE user_id = ? AND symbol = ? AND price IS NOT NULL ORDER BY captured_at ASC',
            [$userId, $symbol]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'at'    => (string) $row['captured_at'],
                'price' => self::num($row['price']),
            ];
        }

        return $out;
    }

    public function writeError(int $userId, string $message, string $attemptedAt): void
    {
        $this->db->execute(
            'INSERT INTO stock_sheet_sync (user_id, status, sync_error, last_attempt_at)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                status = EXCLUDED.status,
                sync_error = EXCLUDED.sync_error,
                last_attempt_at = EXCLUDED.last_attempt_at,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, 'error', $message, $attemptedAt]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function quotes(int $userId, string $sheet): array
    {
        $rows = $this->db->fetchAll(
            'SELECT role, exchange, symbol, name, proxy_for, price, open_price, high_price, low_price,
                    change_amount, change_pct, prev_close, high_52w, low_52w, pct_off_52w, volume, avg_volume,
                    market_cap, pe, eps, beta, shares_outstanding, currency, thesis, last_reviewed
             FROM stock_sheet_quotes WHERE user_id = ? AND sheet = ? ORDER BY sort_order ASC, symbol ASC',
            [$userId, $sheet]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'role'               => self::text($row['role']),
                'exchange'           => self::text($row['exchange']),
                'symbol'             => (string) $row['symbol'],
                'name'               => self::text($row['name']),
                'proxy_for'          => $sheet === 'commodity' ? self::text($row['proxy_for']) : null,
                'price'              => self::num($row['price']),
                'open'               => self::num($row['open_price']),
                'high'               => self::num($row['high_price']),
                'low'                => self::num($row['low_price']),
                'change'             => self::num($row['change_amount']),
                'change_pct'         => self::num($row['change_pct']),
                'prev_close'         => self::num($row['prev_close']),
                'high_52w'           => self::num($row['high_52w']),
                'low_52w'            => self::num($row['low_52w']),
                'pct_off_52w'        => self::num($row['pct_off_52w']),
                'volume'             => self::num($row['volume']),
                'avg_volume'         => self::num($row['avg_volume']),
                'market_cap'         => self::num($row['market_cap']),
                'pe'                 => self::num($row['pe']),
                'eps'                => self::num($row['eps']),
                'beta'               => self::num($row['beta']),
                'shares_outstanding' => self::num($row['shares_outstanding']),
                'currency'           => self::text($row['currency']),
                'thesis'             => self::text($row['thesis']),
                'last_reviewed'      => $row['last_reviewed'] !== null && $row['last_reviewed'] !== '' ? (string) $row['last_reviewed'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writeQuotes(int $userId, string $sheet, array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $symbol = (string) $row['symbol'];
            $keep[$symbol] = true;
            $this->db->execute(
                'INSERT INTO stock_sheet_quotes (
                    user_id, sheet, role, exchange, symbol, name, proxy_for, price, open_price, high_price, low_price,
                    change_amount, change_pct, prev_close, high_52w, low_52w, pct_off_52w, volume, avg_volume,
                    market_cap, pe, eps, beta, shares_outstanding, currency, thesis, last_reviewed, sort_order
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, sheet, symbol) DO UPDATE SET
                    role = EXCLUDED.role,
                    exchange = EXCLUDED.exchange,
                    name = EXCLUDED.name,
                    proxy_for = EXCLUDED.proxy_for,
                    price = EXCLUDED.price,
                    open_price = EXCLUDED.open_price,
                    high_price = EXCLUDED.high_price,
                    low_price = EXCLUDED.low_price,
                    change_amount = EXCLUDED.change_amount,
                    change_pct = EXCLUDED.change_pct,
                    prev_close = EXCLUDED.prev_close,
                    high_52w = EXCLUDED.high_52w,
                    low_52w = EXCLUDED.low_52w,
                    pct_off_52w = EXCLUDED.pct_off_52w,
                    volume = EXCLUDED.volume,
                    avg_volume = EXCLUDED.avg_volume,
                    market_cap = EXCLUDED.market_cap,
                    pe = EXCLUDED.pe,
                    eps = EXCLUDED.eps,
                    beta = EXCLUDED.beta,
                    shares_outstanding = EXCLUDED.shares_outstanding,
                    currency = EXCLUDED.currency,
                    thesis = EXCLUDED.thesis,
                    last_reviewed = EXCLUDED.last_reviewed,
                    sort_order = EXCLUDED.sort_order,
                    updated_at = (now() AT TIME ZONE \'UTC\')',
                [
                    $userId,
                    $sheet,
                    $row['role'] ?? null,
                    $row['exchange'] ?? null,
                    $symbol,
                    $row['name'] ?? null,
                    $row['proxy_for'] ?? null,
                    $row['price'] ?? null,
                    $row['open'] ?? null,
                    $row['high'] ?? null,
                    $row['low'] ?? null,
                    $row['change'] ?? null,
                    $row['change_pct'] ?? null,
                    $row['prev_close'] ?? null,
                    $row['high_52w'] ?? null,
                    $row['low_52w'] ?? null,
                    $row['pct_off_52w'] ?? null,
                    $row['volume'] ?? null,
                    $row['avg_volume'] ?? null,
                    $row['market_cap'] ?? null,
                    $row['pe'] ?? null,
                    $row['eps'] ?? null,
                    $row['beta'] ?? null,
                    $row['shares_outstanding'] ?? null,
                    $row['currency'] ?? null,
                    $row['thesis'] ?? null,
                    $row['last_reviewed'] ?? null,
                    (int) ($row['sort_order'] ?? 0),
                ]
            );
        }
        $existing = $this->db->fetchAll(
            'SELECT symbol FROM stock_sheet_quotes WHERE user_id = ? AND sheet = ?',
            [$userId, $sheet]
        );
        foreach ($existing as $row) {
            $symbol = (string) $row['symbol'];
            if (!isset($keep[$symbol])) {
                $this->db->execute(
                    'DELETE FROM stock_sheet_quotes WHERE user_id = ? AND sheet = ? AND symbol = ?',
                    [$userId, $sheet, $symbol]
                );
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function writePlay(int $userId, array $rows): void
    {
        $keep = [];
        foreach ($rows as $row) {
            $date = (string) $row['date'];
            $ticker = (string) $row['ticker'];
            $keep[$date . '|' . $ticker] = true;
            $this->db->execute(
                'INSERT INTO stock_play_trades (user_id, trade_date, ticker, amount, shares, price, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, trade_date, ticker) DO UPDATE SET
                    amount = EXCLUDED.amount,
                    shares = EXCLUDED.shares,
                    price = EXCLUDED.price,
                    sort_order = EXCLUDED.sort_order,
                    updated_at = (now() AT TIME ZONE \'UTC\')',
                [
                    $userId,
                    $date,
                    $ticker,
                    $row['amount'] ?? null,
                    $row['shares'] ?? null,
                    $row['price'] ?? null,
                    (int) ($row['sort_order'] ?? 0),
                ]
            );
        }
        $existing = $this->db->fetchAll(
            'SELECT trade_date, ticker FROM stock_play_trades WHERE user_id = ?',
            [$userId]
        );
        foreach ($existing as $row) {
            $key = (string) $row['trade_date'] . '|' . (string) $row['ticker'];
            if (!isset($keep[$key])) {
                $this->db->execute(
                    'DELETE FROM stock_play_trades WHERE user_id = ? AND trade_date = ? AND ticker = ?',
                    [$userId, (string) $row['trade_date'], (string) $row['ticker']]
                );
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyStatus(): array
    {
        return [
            'status'          => 'never',
            'sync_error'      => null,
            'last_synced_at'  => null,
            'last_attempt_at' => null,
            'watchlist_count' => 0,
            'commodity_count' => 0,
            'play_count'      => 0,
        ];
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
