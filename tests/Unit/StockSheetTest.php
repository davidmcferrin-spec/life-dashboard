<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Stock\GoogleSheetClient;
use Dashboard\Stock\SheetException;
use Dashboard\Stock\SheetFreshness;
use Dashboard\Stock\SheetGrid;
use Dashboard\Stock\SheetReader;
use Dashboard\Stock\SheetTransport;
use Dashboard\Stock\StockSheetConfig;
use Dashboard\Stock\StockSheetSettings;
use Dashboard\Stock\StockSheetStore;
use Dashboard\Stock\StockSheetSync;
use Dashboard\Support\Clock;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class StockSheetTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::overlay('APP_SECRET', null);
    }

    public function testFormattedAndRawValuesUseHeaderNamesAndStopAtAnEmptySymbol(): void
    {
        $rows = SheetGrid::watchlist([
            ['Price', 'Notes', 'Change %', 'Symbol', 'Role', 'Change ($ vs previous close)', 'P/E', 'Market Cap', 'Volume', "Why I'm tracking / thesis", 'Last reviewed'],
            ['$635.54', 'ignore me', '-3.41%', 'spy', 'Benchmark', '-$4.10', '', '', '6,976,112', 'Index', '11/23/2026'],
            ['10', 'gap', '1%', '', 'Watch', '1', '12', '9', '1', 'nope', ''],
            ['1', 'later', '9%', 'AAPL', 'Watch', '1', '1', '1', '1', 'too late', ''],
        ]);
        self::assertCount(1, $rows);
        self::assertSame('SPY', $rows[0]['symbol']);
        self::assertSame('Benchmark', $rows[0]['role']);
        self::assertEqualsWithDelta(635.54, $rows[0]['price'], 0.00001);
        self::assertEqualsWithDelta(-3.41, $rows[0]['change_pct'], 0.00001);
        self::assertEqualsWithDelta(-4.1, $rows[0]['change'], 0.00001);
        self::assertNull($rows[0]['pe']);
        self::assertNull($rows[0]['market_cap']);
        self::assertEqualsWithDelta(6976112.0, $rows[0]['volume'], 0.00001);
        self::assertSame('Index', $rows[0]['thesis']);
        self::assertSame('2026-11-23', $rows[0]['last_reviewed']);
        self::assertArrayNotHasKey('notes', $rows[0]);

        $raw = SheetGrid::watchlist([
            ['Symbol', 'Price', 'Change %', 'Market Cap', 'P/E', 'EPS', 'Beta'],
            ['QQQ', 480.1, -0.002, 2500000000000, null, '', null],
            ['IWM', 200, -0.0341, null, null, null, 1.1],
        ]);
        self::assertSame(480.1, $raw[0]['price']);
        self::assertEqualsWithDelta(-0.2, $raw[0]['change_pct'], 0.00001);
        self::assertSame(2500000000000.0, $raw[0]['market_cap']);
        self::assertNull($raw[0]['pe']);
        self::assertNull($raw[0]['eps']);
        self::assertNull($raw[1]['market_cap']);
        self::assertEqualsWithDelta(-3.41, $raw[1]['change_pct'], 0.00001);
        self::assertSame(1.1, $raw[1]['beta']);

        $commodities = SheetGrid::commodities([
            ['Name', 'Proxy-for', 'Symbol', 'Price', 'Role', 'Extra'],
            ['Gold', 'Gold spot', 'gld', 412.5, 'Watch', 'not a spot'],
            ['', '', '', '', '', ''],
            ['Silver', 'Silver spot', 'SLV', 30, 'Watch', 'after the blank'],
        ]);
        self::assertCount(1, $commodities);
        self::assertSame('GLD', $commodities[0]['symbol']);
        self::assertSame('Gold spot', $commodities[0]['proxy_for']);
        self::assertSame(412.5, $commodities[0]['price']);

        $play = SheetGrid::play([
            ['Note', 'Price (per share at buy)', 'Shares', 'Amount ($ invested)', 'Ticker', 'Date'],
            ['column G stays out', 500, '2', '$1,000.00', 'aapl', '1/15/2027'],
            ['still a note', 10, 1, 10, '', '2/1/2027'],
            ['too late', 10, 1, 10, 'MSFT', 46037],
        ]);
        self::assertCount(1, $play);
        self::assertSame('AAPL', $play[0]['ticker']);
        self::assertSame('2027-01-15', $play[0]['date']);
        self::assertEqualsWithDelta(1000.0, $play[0]['amount'], 0.00001);
        self::assertEqualsWithDelta(2.0, $play[0]['shares'], 0.00001);
        self::assertEqualsWithDelta(500.0, $play[0]['price'], 0.00001);
        self::assertArrayNotHasKey('note', $play[0]);
        self::assertSame('2026-01-15', SheetGrid::date(46037));
        self::assertNull(SheetGrid::number(null));
        self::assertNull(SheetGrid::number(''));
        self::assertNull(SheetGrid::number('#N/A'));
    }

    public function testDuplicateSymbolKeepsTheLaterRow(): void
    {
        $rows = SheetGrid::watchlist([
            ['Symbol', 'Price', 'Role'],
            ['SPY', 1, 'Watch'],
            ['spy', 2, 'Benchmark'],
        ]);
        self::assertCount(1, $rows);
        self::assertSame(2.0, $rows[0]['price']);
        self::assertSame('Benchmark', $rows[0]['role']);
    }

    public function testSheetClientAsksForRawValuesAndAReadOnlyScope(): void
    {
        $pem = $this->privateKey();
        if ($pem === null) {
            self::markTestSkipped('OpenSSL could not mint a test key.');
        }
        $http = new RecordingSheetTransport();
        $client = new GoogleSheetClient($http);
        $grids = $client->fetch([
            'client_email' => 'sheet@test.iam.gserviceaccount.com',
            'private_key'  => $pem,
            'token_uri'    => 'https://evil.example/token',
        ], StockSheetSettings::DEFAULT_SPREADSHEET_ID);

        self::assertSame('SPY', $grids['Watchlist'][1][0]);
        self::assertSame([], $grids['Play Account']);
        $hosts = [];
        $post = 0;
        $get = 0;
        foreach ($http->calls as $call) {
            $parts = parse_url($call['url']);
            $hosts[] = $parts['host'] ?? '';
            self::assertStringNotContainsString('ya29', $call['url']);
            self::assertStringNotContainsString('PRIVATE KEY', $call['url']);
            if ($call['method'] === 'POST') {
                $post++;
                self::assertSame('https', $parts['scheme'] ?? '');
                self::assertSame('oauth2.googleapis.com', $parts['host'] ?? '');
                parse_str((string) $call['body'], $form);
                self::assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $form['grant_type'] ?? '');
                $payload = explode('.', (string) $form['assertion'])[1];
                $pad = str_repeat('=', (4 - strlen($payload) % 4) % 4);
                $claims = json_decode(base64_decode(strtr($payload . $pad, '-_', '+/')), true);
                self::assertSame(GoogleSheetClient::READONLY_SCOPE, $claims['scope']);
                self::assertSame(GoogleSheetClient::TOKEN_URL, $claims['aud']);
            } else {
                $get++;
                self::assertSame('GET', $call['method']);
                self::assertSame('sheets.googleapis.com', $parts['host'] ?? '');
                self::assertStringContainsString('valueRenderOption=UNFORMATTED_VALUE', $call['url']);
                self::assertStringContainsString('dateTimeRenderOption=FORMATTED_STRING', $call['url']);
                self::assertStringNotContainsString(':batchUpdate', $call['url']);
                self::assertStringStartsWith('Bearer ', $call['headers']['Authorization']);
            }
        }
        self::assertSame(1, $post);
        self::assertSame(3, $get);
        self::assertNotContains('evil.example', $hosts);
        self::assertSame('nope [redacted]', SheetException::safe('nope ya29.secret-token'));
        self::assertStringNotContainsString('secret-token', SheetException::safe('Bearer ya29.secret-token'));
    }

    public function testSyncIsolatesUsersAndSkipsUntilAKeyIsSaved(): void
    {
        $settings = new ArraySheetSettings();
        $settings->users[1] = [
            'configured' => true,
            'spreadsheet_id' => '1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'account' => ['client_email' => 'a@test.iam.gserviceaccount.com', 'private_key' => 'key'],
        ];
        $settings->users[2] = [
            'configured' => true,
            'spreadsheet_id' => '1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
            'account' => ['client_email' => 'b@test.iam.gserviceaccount.com', 'private_key' => 'key'],
        ];
        $reader = new GridSheetReader();
        $reader->bySheet['1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'] = [
            'Watchlist' => [
                ['Symbol', 'Price', 'Role', 'Change %'],
                ['SPY', '$635.54', 'Benchmark', '-3.41%'],
                ['', '', '', ''],
                ['AAPL', '1', 'Watch', '1%'],
            ],
            'Commodities' => [
                ['Symbol', 'Proxy-for', 'Price'],
                ['GLD', 'Gold spot', 412],
            ],
            'Play Account' => [
                ['Date', 'Ticker', 'Amount ($ invested)', 'Shares', 'Price (per share at buy)'],
                ['2027-01-15', 'AAPL', 1000, 2, 500],
            ],
        ];
        $reader->bySheet['1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'] = [
            'Watchlist' => [
                ['Symbol', 'Price'],
                ['QQQ', 480],
            ],
            'Commodities' => [],
            'Play Account' => [],
        ];
        $store = new MemorySheetStore();
        $clock = new Clock('America/Chicago', new DateTimeImmutable('2026-11-23 15:00:00', new DateTimeZone('UTC')));
        $sync = new StockSheetSync($settings, $store, $reader, $clock);

        $skipped = $sync->syncUser(9);
        self::assertFalse($skipped['configured']);
        self::assertSame(0, $reader->calls);
        self::assertSame([], $store->watchlist(9));

        $first = $sync->syncUser(1);
        $second = $sync->syncUser(2);
        self::assertTrue($first['ok']);
        self::assertTrue($second['ok']);
        self::assertSame(['SPY'], array_column($store->watchlist(1), 'symbol'));
        self::assertSame(['QQQ'], array_column($store->watchlist(2), 'symbol'));
        self::assertSame('Gold spot', $store->commodities(1)[0]['proxy_for']);
        self::assertSame([], $store->commodities(2));
        self::assertSame('AAPL', $store->play(1)[0]['ticker']);
        self::assertSame([], $store->play(2));
        self::assertFalse($sync->status(1)['stale']);

        $reader->fail = true;
        $failed = $sync->syncUser(1);
        self::assertFalse($failed['ok']);
        self::assertSame(['SPY'], array_column($store->watchlist(1), 'symbol'));
        self::assertSame('error', $sync->status(1)['status']);
        self::assertSame(['QQQ'], array_column($store->watchlist(2), 'symbol'));

        $reader->fail = false;
        $reader->bySheet['1aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']['Watchlist'] = [
            ['Symbol', 'Price', 'Role'],
            ['IWM', 200, 'Watch'],
            ['SPY', 630, 'Benchmark'],
        ];
        $sync->syncUser(1);
        self::assertSame(['IWM', 'SPY'], array_column($store->watchlist(1), 'symbol'));
        self::assertSame(['QQQ'], array_column($store->watchlist(2), 'symbol'));
    }

    public function testSettingsStayEncryptedAndMaskedPerUser(): void
    {
        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new PrefMemory();
        $settings = new StockSheetSettings($db);
        $key = '{"client_email":"sheet-a@test.iam.gserviceaccount.com","private_key":"-----BEGIN PRIVATE KEY-----\nTEST\n-----END PRIVATE KEY-----\n"}';
        $other = '{"client_email":"sheet-b@test.iam.gserviceaccount.com","private_key":"-----BEGIN PRIVATE KEY-----\nOTHER\n-----END PRIVATE KEY-----\n"}';
        $view = $settings->save(1, [
            'spreadsheet_id' => 'https://docs.google.com/spreadsheets/d/' . StockSheetSettings::DEFAULT_SPREADSHEET_ID . '/edit',
            'service_account_json' => $key,
        ]);
        $settings->save(2, ['service_account_json' => $other]);
        self::assertTrue($view['configured']);
        self::assertSame('set', $view['key_hint']);
        self::assertSame(StockSheetSettings::DEFAULT_SPREADSHEET_ID, $view['spreadsheet_id']);
        self::assertStringNotContainsString('PRIVATE KEY', json_encode($view));
        self::assertStringNotContainsString('sheet-a@', json_encode($view));
        $stored = json_encode($db->rows);
        self::assertStringNotContainsString('BEGIN PRIVATE KEY', $stored);
        self::assertSame('sheet-a@test.iam.gserviceaccount.com', $settings->credentials(1)['account']['client_email']);
        self::assertSame('sheet-b@test.iam.gserviceaccount.com', $settings->credentials(2)['account']['client_email']);
        $kept = $settings->save(1, ['spreadsheet_id' => '1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'service_account_json' => '']);
        self::assertTrue($kept['configured']);
        self::assertSame('1bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', $settings->credentials(1)['spreadsheet_id']);
        self::assertStringContainsString('PRIVATE KEY', $settings->credentials(1)['account']['private_key']);
        self::assertNull($settings->credentials(9));
        $blank = $settings->view(9);
        self::assertFalse($blank['configured']);
        self::assertSame(StockSheetSettings::DEFAULT_SPREADSHEET_ID, $blank['spreadsheet_id']);
        try {
            StockSheetSettings::parseAccount('{');
            self::fail('invalid json should be rejected');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('service_account_json', $e->fields());
        }
    }

    public function testFreshnessFollowsTheChicagoSession(): void
    {
        $tz = new DateTimeZone('America/Chicago');
        $open = new DateTimeImmutable('2026-11-23 15:00:00', new DateTimeZone('UTC'));
        self::assertFalse(SheetFreshness::stale('2026-11-23 14:00:00', $open, $tz, 'ok'));
        self::assertTrue(SheetFreshness::stale('2026-11-23 12:00:00', $open, $tz, 'ok'));
        self::assertTrue(SheetFreshness::stale('2026-11-23 14:50:00', $open, $tz, 'error'));
        $saturday = new DateTimeImmutable('2026-11-21 18:00:00', new DateTimeZone('UTC'));
        self::assertFalse(SheetFreshness::stale('2026-11-20 21:30:00', $saturday, $tz, 'ok'));
    }

    public function testBriefUsesBenchmarksAndDoesNotInventAPortfolio(): void
    {
        $text = \Dashboard\Brief\BriefText::render([
            'calendar' => ['connected' => false],
            'digests' => [],
            'money' => ['configured' => false],
            'home' => ['configured' => false],
            'music' => ['connected' => false],
            'health' => ['configured' => false, 'stored' => false],
            'market' => [
                'configured' => true,
                'stale' => false,
                'benchmarks' => [['symbol' => 'SPY', 'price' => 635.54, 'change_pct' => -0.2]],
                'movers' => [['symbol' => 'NVDA', 'price' => 100, 'change_pct' => -3.41]],
            ],
        ]);
        self::assertStringContainsString('SPY $635.54', $text);
        self::assertStringContainsString('NVDA', $text);
        self::assertStringContainsString('-3.41%', $text);
        self::assertStringContainsString('Quotes only, not an account balance.', $text);
        self::assertStringNotContainsString('cost basis', strtolower($text));
        self::assertStringNotContainsString('profit', strtolower($text));
    }

    public function testRoutesStayOnStocksAndOutOfSectors(): void
    {
        self::assertSame('stocks:read', Scope::forPath('/api/v1/stocks/sheet/watchlist', false));
        self::assertSame('stocks:write', Scope::forPath('/api/v1/stocks/sheet/refresh', true));
        self::assertSame('stock_sheet', ApiRunner::entityFromPath('/api/v1/stocks/sheet/settings'));
        self::assertNull(ApiRunner::sectorFromPath('/api/v1/stocks/sheet/refresh'));
        $allow = Scope::auditEntityAllow(['stocks:read'], 'stock_sheet');
        self::assertContains('stock_sheet', $allow);
        $this->expectException(ForbiddenException::class);
        Scope::auditEntityAllow(['money:read'], 'stock_sheet');
    }

    public function testJobAndTimerAreWired(): void
    {
        $bin = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/bin/lifedash-job');
        $timer = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/systemd/lifedash-job-stocksheet.timer');
        $provision = (string) file_get_contents(dirname(__DIR__, 2) . '/deploy/provision.sh');
        self::assertStringContainsString('cron/sync_stock_sheet.php', $bin);
        self::assertStringContainsString('stocksheet.log', $bin);
        self::assertStringContainsString('America/Chicago', $timer);
        self::assertStringContainsString('Mon..Fri', $timer);
        self::assertStringContainsString('08..15:30', $timer);
        self::assertStringContainsString('lifedash-job@stocksheet.service', $timer);
        self::assertStringContainsString('stocksheet', $provision);
        self::assertFileExists(dirname(__DIR__, 2) . '/database/pgsql/0065_stock_sheet.sql');
    }

    private function privateKey(): ?string
    {
        if (!function_exists('openssl_pkey_new')) {
            return null;
        }
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            return null;
        }
        $pem = '';
        if (!openssl_pkey_export($key, $pem)) {
            return null;
        }

        return $pem;
    }
}

final class RecordingSheetTransport implements SheetTransport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    public function request(string $method, string $url, array $headers, ?string $body): array
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];
        if (str_contains($url, 'oauth2.googleapis.com/token')) {
            return ['status' => 200, 'body' => '{"access_token":"ya29.test-token","token_type":"Bearer"}'];
        }
        if (str_contains($url, 'Play%20Account')) {
            return ['status' => 400, 'body' => '{"error":{"message":"Unable to parse range: Play Account"}}'];
        }

        return ['status' => 200, 'body' => '{"values":[["Symbol","Price"],["SPY",10]]}'];
    }
}

final class ArraySheetSettings implements StockSheetConfig
{
    /** @var array<int, array<string, mixed>> */
    public array $users = [];

    public function save(int $userId, array $input): array
    {
        return $this->view($userId);
    }

    public function view(int $userId): array
    {
        $row = $this->users[$userId] ?? null;

        return [
            'spreadsheet_id' => is_array($row) ? (string) $row['spreadsheet_id'] : StockSheetSettings::DEFAULT_SPREADSHEET_ID,
            'key_hint'       => is_array($row) && !empty($row['configured']) ? 't.com' : '',
            'configured'     => is_array($row) && !empty($row['configured']),
        ];
    }

    public function credentials(int $userId): ?array
    {
        $row = $this->users[$userId] ?? null;
        if (!is_array($row) || empty($row['configured'])) {
            return null;
        }

        return [
            'spreadsheet_id' => (string) $row['spreadsheet_id'],
            'account'        => $row['account'],
        ];
    }

    public function userIds(): array
    {
        return array_keys($this->users);
    }
}

final class GridSheetReader implements SheetReader
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

        return $this->bySheet[$spreadsheetId] ?? [
            'Watchlist' => [],
            'Commodities' => [],
            'Play Account' => [],
        ];
    }
}

final class MemorySheetStore implements StockSheetStore
{
    /** @var array<int, array{watchlist: list<array<string, mixed>>, commodities: list<array<string, mixed>>, play: list<array<string, mixed>>, status: array<string, mixed>}> */
    private array $data = [];

    public function watchlist(int $userId): array
    {
        return $this->data[$userId]['watchlist'] ?? [];
    }

    public function commodities(int $userId): array
    {
        return $this->data[$userId]['commodities'] ?? [];
    }

    public function play(int $userId): array
    {
        return $this->data[$userId]['play'] ?? [];
    }

    public function status(int $userId): array
    {
        return $this->data[$userId]['status'] ?? [
            'status' => 'never',
            'sync_error' => null,
            'last_synced_at' => null,
            'last_attempt_at' => null,
            'watchlist_count' => 0,
            'commodity_count' => 0,
            'play_count' => 0,
        ];
    }

    public function replace(int $userId, array $watchlist, array $commodities, array $play, array $status): void
    {
        $previous = $this->status($userId);
        $this->data[$userId]['watchlist'] = array_values($watchlist);
        $this->data[$userId]['commodities'] = array_values($commodities);
        $this->data[$userId]['play'] = array_values($play);
        $this->data[$userId]['status'] = [
            'status' => 'ok',
            'sync_error' => null,
            'last_synced_at' => $status['last_synced_at'] ?? $previous['last_synced_at'],
            'last_attempt_at' => $status['last_attempt_at'] ?? $status['last_synced_at'] ?? null,
            'watchlist_count' => count($watchlist),
            'commodity_count' => count($commodities),
            'play_count' => count($play),
        ];
    }

    public function writeError(int $userId, string $message, string $attemptedAt): void
    {
        $previous = $this->status($userId);
        $this->data[$userId]['watchlist'] = $this->data[$userId]['watchlist'] ?? [];
        $this->data[$userId]['commodities'] = $this->data[$userId]['commodities'] ?? [];
        $this->data[$userId]['play'] = $this->data[$userId]['play'] ?? [];
        $previous['status'] = 'error';
        $previous['sync_error'] = $message;
        $previous['last_attempt_at'] = $attemptedAt;
        $this->data[$userId]['status'] = $previous;
    }
}

final class PrefMemory extends Database
{
    public function __construct()
    {
    }

    /** @var array<string, array{id: int, value: string}> */
    public array $rows = [];

    private int $seq = 1;

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_starts_with($sql, 'SELECT pref_value')) {
            $key = $params[0] . '|' . $params[1];

            return isset($this->rows[$key]) ? ['pref_value' => $this->rows[$key]['value']] : null;
        }
        if (str_starts_with($sql, 'SELECT id')) {
            $key = $params[0] . '|' . $params[1];

            return isset($this->rows[$key]) ? ['id' => $this->rows[$key]['id']] : null;
        }
        throw new \RuntimeException($sql);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $out = [];
        foreach ($this->rows as $key => $row) {
            [$user, $pref] = explode('|', $key, 2);
            if ($pref === (string) $params[0]) {
                $out[] = ['user_id' => (int) $user];
            }
            unset($row);
        }
        usort($out, static fn (array $a, array $b): int => $a['user_id'] <=> $b['user_id']);

        return $out;
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_starts_with($sql, 'INSERT INTO user_preferences')) {
            $this->rows[$params[0] . '|' . $params[1]] = ['id' => $this->seq++, 'value' => (string) $params[2]];

            return 1;
        }
        if (str_starts_with($sql, 'UPDATE user_preferences')) {
            $key = $params[1] . '|' . $params[2];
            if (!isset($this->rows[$key])) {
                return 0;
            }
            $this->rows[$key]['value'] = (string) $params[0];

            return 1;
        }
        throw new \RuntimeException($sql);
    }
}
