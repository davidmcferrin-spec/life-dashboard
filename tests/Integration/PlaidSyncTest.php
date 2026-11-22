<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Config;
use Dashboard\Money\PlaidException;
use Dashboard\Money\PlaidSync;
use Dashboard\Money\PlaidTransport;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Tracking\Services;

final class PlaidSyncTest extends PgsqlTestCase
{
    private const TOKEN = 'access-sandbox-TESTTOKEN';

    private const SECRET = 'sandbox-secret-99';

    private int $user;

    private int $other;

    private Services $svc;

    private PlaidSync $plaid;

    private \Dashboard\Money\MoneyLedgerService $ledger;

    private ScriptedPlaid $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        Config::overlay('APP_SECRET', 'plaid-test-secret-plaid-test-secret');
        $this->db->execute('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)', ['ada', 'ada@x', 'h']);
        $this->user = (int) $this->db->lastInsertId();
        $this->db->execute('INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)', ['bea', 'bea@x', 'h']);
        $this->other = (int) $this->db->lastInsertId();
        $clock = new Clock('America/Chicago', new \DateTimeImmutable('2026-11-22 18:00:00', new \DateTimeZone('UTC')));
        $this->svc = new Services($this->db, $clock);
        $this->http = new ScriptedPlaid();
        $this->plaid = PlaidSync::make($this->db, $clock, $this->svc->money, $this->svc->plan, $this->http);
        $this->ledger = new \Dashboard\Money\MoneyLedgerService($this->db, $clock, $this->svc->money, $this->svc->plan, $this->plaid);
    }

    protected function tearDown(): void
    {
        Config::overlay('APP_SECRET', null);
        parent::tearDown();
    }

    public function testLinkMapAndSyncKeepOverridesAndHideTheToken(): void
    {
        $view = $this->plaid->saveSettings($this->user, [
            'client_id' => 'clientid12345678',
            'secret' => self::SECRET,
            'environment' => 'sandbox',
        ]);
        self::assertTrue($view['configured']);
        self::assertSame('5678', $view['client_id_hint']);
        self::assertNotSame('', $view['secret_hint']);
        self::assertStringNotContainsString(self::SECRET, json_encode($view));
        $kept = $this->plaid->saveSettings($this->user, ['environment' => 'sandbox', 'secret' => '']);
        self::assertTrue($kept['configured']);

        $checking = $this->svc->money->createAccount($this->user, [
            'name' => 'Checking', 'kind' => 'checking', 'balance' => 800,
        ]);
        $this->http->queue[] = ['access_token' => self::TOKEN, 'item_id' => 'item-1'];
        $this->http->queue[] = ['accounts' => [
            ['account_id' => 'acc-check', 'name' => 'Plaid Checking', 'mask' => '0000', 'type' => 'depository', 'subtype' => 'checking'],
            ['account_id' => 'acc-card', 'name' => 'Plaid Card', 'mask' => '1111', 'type' => 'credit', 'subtype' => 'credit card'],
        ]];
        $item = $this->plaid->exchange($this->user, [
            'public_token' => 'public-sandbox-1',
            'institution_name' => 'First Bank',
            'institution_id' => 'ins_1',
        ]);
        self::assertSame('First Bank', $item['institution_name']);
        self::assertCount(2, $item['accounts']);
        $encoded = json_encode($this->plaid->items($this->user));
        self::assertIsString($encoded);
        self::assertStringNotContainsString(self::TOKEN, $encoded);
        $stored = (string) $this->db->fetchScalar('SELECT access_token_encrypted FROM money_plaid_items WHERE user_id = ?', [$this->user]);
        self::assertStringNotContainsString(self::TOKEN, $stored);
        self::assertSame([], $this->plaid->items($this->other)['items']);

        $byPlaid = [];
        foreach ($item['accounts'] as $row) {
            $byPlaid[$row['plaid_account_id']] = $row;
        }
        $checkRow = $byPlaid['acc-check'];
        $cardRow = $byPlaid['acc-card'];
        $mapped = $this->plaid->mapAccount($this->user, (int) $checkRow['id'], ['account_id' => $checking['id']]);
        self::assertSame((int) $checking['id'], $mapped['account_id']);
        self::assertEqualsWithDelta(800.0, (float) $this->db->fetchScalar('SELECT balance FROM money_accounts WHERE id = ?', [$checking['id']]), 0.001);

        $created = $this->plaid->mapAccount($this->user, (int) $cardRow['id'], ['create' => true]);
        $card = $this->svc->money->account($this->user, (int) $created['account_id']);
        self::assertSame('credit', $card['kind']);
        self::assertEqualsWithDelta(0.0, (float) $card['balance'], 0.001);
        self::assertNull($card['last_synced_at']);
        self::assertTrue($card['stale']);
        $debt = $this->svc->plan->saveDebt($this->user, [
            'name' => 'Card', 'balance' => 10, 'account_id' => $card['id'],
        ]);

        $this->queueSync(false);
        $refresh = $this->ledger->refreshAccounts($this->user, (int) $checking['id']);
        self::assertTrue($refresh['accounts'][0]['refreshed']);
        self::assertTrue($refresh['accounts'][0]['live_sync']);
        self::assertFalse($refresh['accounts'][0]['stale']);
        self::assertEqualsWithDelta(250.5, (float) $this->db->fetchScalar('SELECT balance FROM money_accounts WHERE id = ?', [$checking['id']]), 0.001);
        self::assertEqualsWithDelta(200.25, (float) $this->db->fetchScalar('SELECT available_balance FROM money_accounts WHERE id = ?', [$checking['id']]), 0.001);
        self::assertEqualsWithDelta(500.0, (float) $this->db->fetchScalar('SELECT balance FROM money_accounts WHERE id = ?', [$card['id']]), 0.001);
        self::assertEqualsWithDelta(2000.0, (float) $this->db->fetchScalar('SELECT credit_limit FROM money_accounts WHERE id = ?', [$card['id']]), 0.001);
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_transactions WHERE user_id = ?', [$this->user]));
        $txn = $this->svc->money->transactions($this->user)[0];
        self::assertEqualsWithDelta(-12.5, (float) $txn['amount'], 0.001);
        self::assertSame('Cafe', $txn['payee']);
        $updatedDebt = $this->svc->plan->debt($this->user, (int) $debt['id']);
        self::assertEqualsWithDelta(19.99, (float) $updatedDebt['apr_percent'], 0.001);
        self::assertEqualsWithDelta(25.0, (float) $updatedDebt['minimum_payment'], 0.001);
        self::assertSame(3, $updatedDebt['due_day']);
        self::assertEqualsWithDelta(10.0, (float) $updatedDebt['balance'], 0.001);

        $shown = $this->ledger->setTransactionCategory($this->user, (int) $txn['id'], 'Groceries', true);
        self::assertSame('Groceries', $shown['category']);
        $this->queueSync(true);
        $this->ledger->refreshAccounts($this->user, null);
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_transactions WHERE user_id = ?', [$this->user]));
        $again = $this->svc->money->transaction($this->user, (int) $txn['id']);
        self::assertSame('Groceries', $again['category']);
        self::assertSame('Food And Drink', $again['raw_category']);
        self::assertTrue($again['category_overridden']);

        $this->http->queue[] = new PlaidException('ITEM_LOGIN_REQUIRED', 'Login required');
        $failed = $this->ledger->refreshAccounts($this->user, (int) $checking['id']);
        self::assertFalse($failed['accounts'][0]['refreshed']);
        self::assertStringContainsString('Login required', (string) $failed['accounts'][0]['message']);
        $summary = $this->ledger->summary($this->user);
        self::assertTrue($summary['freshness']['stale']);
        self::assertSame('First Bank', $summary['freshness']['sync_errors'][0]['institution']);
        self::assertStringNotContainsString(self::TOKEN, json_encode($summary));

        $this->http->queue[] = ['removed' => true];
        $this->plaid->disconnect($this->user, (int) $item['id']);
        self::assertSame([], $this->plaid->items($this->user)['items']);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_plaid_items WHERE user_id = ?', [$this->user]));
        try {
            $this->plaid->disconnect($this->other, (int) $item['id']);
            self::fail('another user disconnected the item');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
    }

    private function queueSync(bool $modified): void
    {
        $this->http->queue[] = ['accounts' => [
            ['account_id' => 'acc-check', 'type' => 'depository', 'subtype' => 'checking', 'balances' => ['current' => 250.5, 'available' => 200.25]],
            ['account_id' => 'acc-card', 'type' => 'credit', 'subtype' => 'credit card', 'balances' => ['current' => 500, 'available' => 1500, 'limit' => 2000]],
        ]];
        $txn = [
            'transaction_id' => 'tx-1',
            'account_id' => 'acc-check',
            'amount' => 12.5,
            'date' => '2026-11-20',
            'merchant_name' => 'Cafe',
            'pending' => false,
            'personal_finance_category' => ['primary' => $modified ? 'TRAVEL' : 'FOOD_AND_DRINK'],
        ];
        $this->http->queue[] = [
            'added' => $modified ? [] : [$txn],
            'modified' => $modified ? [$txn] : [],
            'removed' => [['transaction_id' => 'tx-1']],
            'next_cursor' => 'cursor-1',
            'has_more' => false,
        ];
        $this->http->queue[] = ['liabilities' => ['credit' => [[
            'account_id' => 'acc-card',
            'aprs' => [['apr_type' => 'purchase_apr', 'apr_percentage' => 19.99]],
            'minimum_payment_amount' => 25,
            'next_payment_due_date' => '2026-12-03',
        ]]]];
    }

}

final class ScriptedPlaid implements PlaidTransport
{
    /** @var list<array<string, mixed>|PlaidException> */
    public array $queue = [];

    /** @var list<array{url: string, body: array<string, mixed>}> */
    public array $calls = [];

    public function post(string $url, array $body): array
    {
        $this->calls[] = ['url' => $url, 'body' => $body];
        $next = array_shift($this->queue);
        if ($next instanceof PlaidException) {
            throw $next;
        }
        if (!is_array($next)) {
            throw new PlaidException('TEST', 'No scripted Plaid response for ' . $url);
        }

        return $next;
    }
}
