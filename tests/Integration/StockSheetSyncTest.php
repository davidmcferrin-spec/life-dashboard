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
