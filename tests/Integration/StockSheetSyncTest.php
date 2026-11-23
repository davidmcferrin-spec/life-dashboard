<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Config;
use Dashboard\Stock\SheetException;
use Dashboard\Stock\SheetReader;
use Dashboard\Stock\StockSheetSettings;
use Dashboard\Stock\StockSheetSync;
use Dashboard\Support\Clock;
use DateTimeImmutable;
use DateTimeZone;

final class StockSheetSyncTest extends PgsqlTestCase
{
    private int $user;

    private int $other;

    private ScriptedSheetReader $reader;

    private StockSheetSync $sync;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        Config::overlay('APP_SECRET', 'sheet-test-secret-sheet-test-secret');
        $this->db->execute('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)', ['ada', 'ada-sheet@x', 'h']);
        $this->user = (int) $this->db->lastInsertId();
        $this->db->execute('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)', ['bea', 'bea-sheet@x', 'h']);
        $this->other = (int) $this->db->lastInsertId();
        $clock = new Clock('America/Chicago', new DateTimeImmutable('2026-11-23 15:00:00', new DateTimeZone('UTC')));
        $this->reader = new ScriptedSheetReader();
        $this->sync = StockSheetSync::make($this->db, $clock, $this->reader);
    }

    protected function tearDown(): void
    {
        Config::overlay('APP_SECRET', null);
        parent::tearDown();
    }

    public function testEachUserKeepsTheirOwnRowsAndTheKeyStaysEncrypted(): void
    {
        $ada = '1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $bea = '1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $view = $this->sync->saveSettings($this->user, [
            'spreadsheet_id' => $ada,
            'service_account_json' => $this->key('sheet-a@test.iam.gserviceaccount.com'),
        ]);
        self::assertTrue($view['settings']['configured']);
        self::assertStringNotContainsString('PRIVATE KEY', json_encode($view));
        $this->sync->saveSettings($this->other, [
            'spreadsheet_id' => $bea,
            'service_account_json' => $this->key('sheet-b@test.iam.gserviceaccount.com'),
        ]);
        $stored = (string) $this->db->fetchScalar(
            'SELECT pref_value FROM user_preferences WHERE user_id = ? AND pref_key = ?',
            [$this->user, StockSheetSettings::PREF_KEY]
        );
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $stored);

        $this->reader->bySheet[$ada] = $this->grid('SPY', 'GLD');
        $this->reader->bySheet[$bea] = $this->grid('QQQ', 'SLV');
        self::assertTrue($this->sync->syncUser($this->user)['ok']);
        self::assertTrue($this->sync->syncUser($this->other)['ok']);

        $adaSymbols = $this->symbols($this->user, 'watchlist');
        $beaSymbols = $this->symbols($this->other, 'watchlist');
        self::assertSame(['SPY'], $adaSymbols);
        self::assertSame(['QQQ'], $beaSymbols);
        self::assertSame(['GLD'], $this->symbols($this->user, 'commodity'));
        self::assertSame(['SLV'], $this->symbols($this->other, 'commodity'));
        self::assertSame(['SPY'], array_column($this->sync->play($this->user)['rows'], 'ticker'));
        self::assertSame(['QQQ'], array_column($this->sync->play($this->other)['rows'], 'ticker'));

        $plain = $this->sync->syncUser(999999);
        self::assertFalse($plain['configured']);

        $before = $this->reader->calls;
        $missing = $this->sync->syncUser($this->user + $this->other + 10);
        self::assertFalse($missing['configured']);
        self::assertSame($before, $this->reader->calls);

        $this->reader->fail = true;
        self::assertFalse($this->sync->syncUser($this->user)['ok']);
        self::assertSame(['SPY'], $this->symbols($this->user, 'watchlist'));
        self::assertSame(['QQQ'], $this->symbols($this->other, 'watchlist'));

        $this->reader->fail = false;
        $this->reader->bySheet[$ada] = $this->grid('IWM', 'GLD');
        $this->reader->bySheet[$ada]['Watchlist'][] = ['SPY', 630, 'Benchmark', '0%'];
        self::assertTrue($this->sync->syncUser($this->user)['ok']);
        self::assertSame(['IWM', 'SPY'], $this->symbols($this->user, 'watchlist'));
        self::assertSame(['QQQ'], $this->symbols($this->other, 'watchlist'));
    }

    public function testHistoryOutlivesTheRollingSheetAndABlankReload(): void
    {
        $ada = '1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $this->sync->saveSettings($this->user, [
            'spreadsheet_id' => $ada,
            'service_account_json' => $this->key('sheet-a@test.iam.gserviceaccount.com'),
        ]);
        $this->reader->bySheet[$ada] = [
            'Watchlist' => [
                ['Symbol', 'Price', 'Role'],
                ['VOO', 635.54, 'Watch'],
                ['NXST', 196.13, 'Watch'],
            ],
            'Commodities' => [],
            'Play Account' => [],
            'History' => [
                ['Symbol', 'Date', 'Open', 'High', 'Low', 'Close', 'Volume'],
                ['VOO', 45985, 630, 640, 620, 635.54, 1000],
                ['VOO', '2024-06-03', 80, 81, 79, 80.5, 10],
                ['NXST', '2026-11-20', 190, 200, 180, 196.13, 100],
            ],
        ];
        self::assertTrue($this->sync->syncUser($this->user)['ok']);

        $this->reader->bySheet[$ada]['Watchlist'] = [
            ['Symbol', 'Price', 'Role'],
            ['VOO', '', 'Watch'],
        ];
        $this->reader->bySheet[$ada]['History'] = [
            ['Symbol', 'Date', 'Open', 'High', 'Low', 'Close', 'Volume'],
            ['VOO', '2025-11-24', '', '#N/A', '#N/A', '#N/A', 1200],
            ['VOO', '2026-11-21', 636, 638, 634, 637, 4000],
        ];
        self::assertTrue($this->sync->syncUser($this->user)['ok']);

        $bars = $this->bars($this->user);
        self::assertSame('2024-06-03', $bars['VOO|2024-06-03']['bar_date']);
        self::assertEqualsWithDelta(80.5, (float) $bars['VOO|2024-06-03']['close_price'], 0.00001);
        self::assertEqualsWithDelta(635.54, (float) $bars['VOO|2025-11-24']['close_price'], 0.00001);
        self::assertEqualsWithDelta(630.0, (float) $bars['VOO|2025-11-24']['open_price'], 0.00001);
        self::assertEqualsWithDelta(1200.0, (float) $bars['VOO|2025-11-24']['volume'], 0.00001);
        self::assertEqualsWithDelta(637.0, (float) $bars['VOO|2026-11-21']['close_price'], 0.00001);
        self::assertEqualsWithDelta(196.13, (float) $bars['NXST|2026-11-20']['close_price'], 0.00001);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM stock_sheet_bars WHERE user_id = ?', [$this->other]));
        self::assertEqualsWithDelta(635.54, (float) $this->db->fetchScalar(
            'SELECT price FROM stock_sheet_ticks WHERE user_id = ? AND symbol = ?',
            [$this->user, 'VOO']
        ), 0.00001);

        $this->reader->bySheet[$ada]['History'] = [];
        self::assertTrue($this->sync->syncUser($this->user)['ok']);
        self::assertCount(4, $this->bars($this->user));

        $this->reader->bySheet[$ada]['History'] = [['#N/A']];
        self::assertTrue($this->sync->syncUser($this->user)['ok']);
        self::assertCount(4, $this->bars($this->user));

        $year = $this->sync->history($this->user, 'VOO', 'year');
        $dates = array_column($year['ranges']['year']['points'], 'date');
        self::assertContains('2025-11-24', $dates);
        self::assertNotContains('2024-06-03', $dates);
        self::assertSame('09:00', $this->sync->history($this->user, 'VOO', 'day')['ranges']['day']['points'][0]['label']);
    }

    private function key(string $email): string
    {
        return json_encode([
            'client_email' => $email,
            'private_key' => "-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n",
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, list<list<mixed>>>
     */
    private function grid(string $symbol, string $proxy): array
    {
        return [
            'Watchlist' => [
                ['Symbol', 'Price', 'Role', 'Change %'],
                [$symbol, '$10.00', 'Benchmark', '-1.00%'],
            ],
            'Commodities' => [
                ['Symbol', 'Proxy-for', 'Price', 'Role'],
                [$proxy, 'Gold spot', 20, 'Watch'],
            ],
            'Play Account' => [
                ['Date', 'Ticker', 'Amount ($ invested)', 'Shares', 'Price (per share at buy)', 'Note'],
                ['2027-01-15', $symbol, 1000, 2, 500, 'ignored'],
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function symbols(int $userId, string $sheet): array
    {
        $rows = $this->db->fetchAll(
            'SELECT symbol FROM stock_sheet_quotes WHERE user_id = ? AND sheet = ? ORDER BY sort_order ASC, symbol ASC',
            [$userId, $sheet]
        );

        return array_map(static fn (array $row): string => (string) $row['symbol'], $rows);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function bars(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT symbol, bar_date::text AS bar_date, open_price, close_price, volume
             FROM stock_sheet_bars WHERE user_id = ? ORDER BY symbol, bar_date',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[$row['symbol'] . '|' . $row['bar_date']] = $row;
        }

        return $out;
    }
}

final class ScriptedSheetReader implements SheetReader
{
    public int $calls = 0;

    public bool $fail = false;

    /** @var array<string, array<string, list<list<mixed>>>> */
    public array $bySheet = [];

    public function fetch(array $account, string $spreadsheetId): array
    {
        $this->calls++;
        if ($this->fail) {
            throw SheetException::make('Google Sheets request failed.');
        }

        return $this->bySheet[$spreadsheetId];
    }
}
