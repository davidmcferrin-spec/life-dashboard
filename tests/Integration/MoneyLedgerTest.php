<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Auth\Auth;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Tracking\Services;

final class MoneyLedgerTest extends PgsqlTestCase
{
    private int $user;

    private int $other;

    private Services $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['bea', 'bea@x', 'h']
        );
        $this->other = (int) $this->db->lastInsertId();
        $this->svc = new Services(
            $this->db,
            new Clock('America/Chicago', new \DateTimeImmutable('2026-11-22 18:00:00', new \DateTimeZone('UTC')))
        );
    }

    protected function tearDown(): void
    {
        putenv('LIFEDASH_TEST_CAPTURE');
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LIFEDASH_TOOL'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    public function testFreshnessRefreshComponentsAndUtilization(): void
    {
        $account = $this->svc->money->createAccount($this->user, [
            'name' => 'Checking', 'kind' => 'checking', 'balance' => 800,
        ]);
        self::assertSame('2026-11-22 18:00:00', $account['last_synced_at']);
        self::assertFalse($account['stale']);

        $refresh = $this->svc->ledger->refreshAccounts($this->user, (int) $account['id']);
        self::assertFalse($refresh['accounts'][0]['refreshed']);
        self::assertFalse($refresh['accounts'][0]['live_sync']);
        self::assertStringContainsString('No live sync is connected', $refresh['accounts'][0]['message']);
        self::assertSame('2026-11-22 18:00:00', $refresh['accounts'][0]['last_synced_at']);

        $this->db->execute(
            'UPDATE money_accounts SET last_synced_at = ? WHERE id = ?',
            ['2026-11-21 17:00:00', $account['id']]
        );
        $stale = $this->svc->money->account($this->user, (int) $account['id']);
        self::assertTrue($stale['stale']);
        $this->svc->ledger->saveOptions($this->user, ['stale_hours' => 48]);
        self::assertFalse($this->svc->money->account($this->user, (int) $account['id'])['stale']);

        $card = $this->svc->money->createAccount($this->user, [
            'name' => 'Visa', 'kind' => 'credit', 'balance' => 500, 'credit_limit' => 2000,
        ]);
        $debt = $this->svc->plan->saveDebt($this->user, [
            'name' => 'Visa debt', 'balance' => 100,
        ]);
        $before = (float) $this->db->fetchScalar('SELECT balance FROM money_debts WHERE id = ?', [$debt['id']]);
        $parts = $this->svc->ledger->setDebtComponents($this->user, (int) $debt['id'], [
            'principal' => 80,
            'interest' => 10,
        ]);
        self::assertTrue($parts['components_mismatch']);
        self::assertEqualsWithDelta(90.0, $parts['components_sum'], 0.001);
        self::assertEqualsWithDelta($before, (float) $parts['balance'], 0.001);
        self::assertTrue($parts['balance_unchanged']);
        $matched = $this->svc->ledger->setDebtComponents($this->user, (int) $debt['id'], [
            'principal' => 90,
            'unapplied_funds' => 5,
            'late_charges' => 5,
            'fees' => 5,
            'interest' => 5,
        ]);
        self::assertFalse($matched['components_mismatch']);
        self::assertEqualsWithDelta(100.0, $matched['components_sum'], 0.001);
        self::assertEqualsWithDelta($before, (float) $this->db->fetchScalar('SELECT balance FROM money_debts WHERE id = ?', [$debt['id']]), 0.001);

        $util = $this->svc->ledger->creditUtilization($this->user);
        self::assertCount(1, $util['cards']);
        self::assertEqualsWithDelta(25.0, $util['overall']['utilization_percent'], 0.01);
        self::assertArrayHasKey('last_synced_at', $util['cards'][0]);
        self::assertArrayHasKey('stale', $util['cards'][0]);

        try {
            $this->svc->ledger->debtComponents($this->other, (int) $debt['id']);
            self::fail('another user read the debt');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
    }

    public function testPaymentsInflowsPotsAndCategoryOverrides(): void
    {
        $bill = $this->svc->bills->saveBill($this->user, [
            'name' => 'Rent', 'amount' => 100, 'cadence' => 'monthly', 'due_day' => 1,
        ]);
        $payment = $this->svc->ledger->addPayment($this->user, [
            'bill_id' => $bill['id'], 'amount' => 100, 'status' => 'scheduled', 'due_on' => '2026-12-01',
        ]);
        self::assertSame('scheduled', $payment['status']);
        $pending = $this->svc->ledger->updatePayment($this->user, (int) $payment['id'], ['status' => 'pending']);
        self::assertSame('pending', $pending['status']);
        self::assertSame([], $this->svc->ledger->payments($this->other));

        $account = $this->svc->money->createAccount($this->user, ['name' => 'Cash', 'kind' => 'cash', 'balance' => 50]);
        $txn = $this->svc->money->addTransaction($this->user, [
            'account_id' => $account['id'],
            'amount' => 40,
            'date' => '2026-11-20',
            'payee' => 'Cafe',
            'category' => 'Uncategorized',
        ]);
        $raw = (string) $this->db->fetchScalar('SELECT category FROM money_transactions WHERE id = ?', [$txn['id']]);
        $inflow = $this->svc->ledger->addInflow($this->user, [
            'kind' => 'refund', 'source' => 'Cafe', 'amount' => 40, 'expected_on' => '2026-11-25',
        ]);
        $received = $this->svc->ledger->markInflowReceived($this->user, (int) $inflow['id'], (int) $txn['id'], '2026-11-22');
        self::assertSame('received', $received['status']);
        self::assertSame((int) $txn['id'], $received['transaction_id']);
        self::assertSame($raw, (string) $this->db->fetchScalar('SELECT category FROM money_transactions WHERE id = ?', [$txn['id']]));
        self::assertEqualsWithDelta(40.0, (float) $this->db->fetchScalar('SELECT amount FROM money_transactions WHERE id = ?', [$txn['id']]), 0.001);

        $shown = $this->svc->ledger->setTransactionCategory($this->user, (int) $txn['id'], 'Groceries', true);
        self::assertSame('Groceries', $shown['category']);
        self::assertSame('Uncategorized', $shown['raw_category']);
        self::assertTrue($shown['category_overridden']);
        self::assertSame('Uncategorized', (string) $this->db->fetchScalar('SELECT category FROM money_transactions WHERE id = ?', [$txn['id']]));

        $later = $this->svc->money->addTransaction($this->user, [
            'account_id' => $account['id'],
            'amount' => -12,
            'date' => '2026-11-21',
            'payee' => 'Cafe',
            'category' => 'Dining',
        ], 'import');
        self::assertSame('Groceries', $later['category']);
        self::assertSame('Dining', $later['raw_category']);
        $untouched = $this->svc->money->addTransaction($this->user, [
            'account_id' => $account['id'],
            'amount' => -8,
            'date' => '2026-11-18',
            'payee' => 'Other store',
            'category' => 'Dining',
        ], 'import');
        self::assertSame('Dining', $untouched['category']);
        self::assertFalse($untouched['category_overridden']);

        $reverted = $this->svc->ledger->revertTransactionCategory($this->user, (int) $txn['id'], true);
        self::assertSame('Uncategorized', $reverted['category']);
        self::assertFalse($reverted['category_overridden']);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_merchant_rules WHERE user_id = ?', [$this->user]));

        $pot = $this->svc->ledger->saveBenefitPot($this->user, [
            'kind' => 'fsa',
            'name' => 'Health',
            'balance' => 675,
            'plan_year' => 2026,
            'deadline' => '2026-12-31',
            'use_it_or_lose_it' => 675,
        ]);
        self::assertSame('fsa', $pot['kind']);
        self::assertEqualsWithDelta(675.0, $pot['balance'], 0.001);
        self::assertSame('2026-12-31', $pot['deadline']);
        self::assertFalse($pot['stale']);
        self::assertSame([], $this->svc->ledger->benefitPots($this->other));
        self::assertCount(1, $this->svc->ledger->benefitPots($this->user));
    }

    public function testFoodWeekUsesEatDateAndTheSummaryIsOneRead(): void
    {
        $account = $this->svc->money->createAccount($this->user, ['name' => 'Card', 'kind' => 'checking', 'balance' => 200]);
        $this->svc->money->addTransaction($this->user, [
            'account_id' => $account['id'],
            'amount' => -40,
            'date' => '2026-11-16',
            'payee' => 'Market',
            'category' => 'Groceries',
            'eaten_on' => '2026-11-23',
        ]);
        $eating = $this->svc->money->addTransaction($this->user, [
            'account_id' => $account['id'],
            'amount' => -20,
            'date' => '2026-11-24',
            'payee' => 'Diner',
            'category' => 'Eating out',
        ]);
        $this->svc->money->addTransaction($this->user, [
            'account_id' => $account['id'],
            'amount' => -60,
            'date' => '2026-11-18',
            'payee' => 'Market',
            'category' => 'Groceries',
        ]);
        $this->svc->money->addTransaction($this->user, [
            'account_id' => $account['id'],
            'amount' => -15,
            'date' => '2026-11-23',
            'payee' => 'Store',
            'category' => 'Food',
        ]);
        $week = $this->svc->ledger->foodWeekTotals($this->user, '2026-11-25');
        self::assertSame('2026-11-22', $week['week_start']);
        self::assertSame('2026-11-28', $week['week_end']);
        self::assertEqualsWithDelta(40.0, $week['groceries']['spent'], 0.001);
        self::assertEqualsWithDelta(20.0, $week['eating_out']['spent'], 0.001);
        self::assertEqualsWithDelta(150.0, $week['groceries']['target'], 0.001);
        self::assertEqualsWithDelta(125.0, $week['eating_out']['target'], 0.001);
        self::assertEqualsWithDelta(60.0, $week['previous_week']['groceries'], 0.001);
        self::assertEqualsWithDelta(50.0, $week['two_week_average']['groceries'], 0.001);

        $moved = $this->svc->ledger->setEatenOn($this->user, (int) $eating['id'], '2026-11-18');
        self::assertSame('2026-11-18', $moved['eaten_on']);
        self::assertEqualsWithDelta(-20.0, $moved['amount'], 0.001);
        $after = $this->svc->ledger->foodWeekTotals($this->user, '2026-11-22');
        self::assertEqualsWithDelta(0.0, $after['eating_out']['spent'], 0.001);
        self::assertEqualsWithDelta(20.0, $after['previous_week']['eating_out'], 0.001);

        $summary = $this->svc->ledger->summary($this->user);
        self::assertEqualsWithDelta(200.0, $summary['cash'], 0.001);
        self::assertEqualsWithDelta(200.0, $summary['net_worth'], 0.001);
        self::assertSame('2026-11-22', $summary['food_week']['week_start']);
        self::assertArrayHasKey('last_synced_at', $summary['accounts'][0]);
        self::assertArrayHasKey('freshness', $summary);
        self::assertSame(24, $summary['freshness']['threshold_hours']);

        $widget = $this->svc->widgets->create($this->user, 'money_summary', 'Money', []);
        $payload = $this->svc->widgetData->forWidget($this->user, (int) $widget['id']);
        self::assertSame([], $payload['metric_ids']);
        self::assertEqualsWithDelta(200.0, $payload['net_worth'], 0.001);
        self::assertSame('2026-11-22', $payload['food_week']['week_start']);

        $foodWidget = $this->svc->widgets->create($this->user, 'food_week', 'Food', []);
        $foodPayload = $this->svc->widgetData->forWidget($this->user, (int) $foodWidget['id']);
        self::assertArrayHasKey('split', $foodPayload);
        self::assertSame('2026-11-22', $foodPayload['split']['week_start']);
    }

    public function testAPaymentWriteIsAuditedForTheMoneyToken(): void
    {
        $bill = $this->svc->bills->saveBill($this->user, [
            'name' => 'Power', 'amount' => 40, 'cadence' => 'monthly', 'due_day' => 3,
        ]);
        $payment = $this->svc->ledger->addPayment($this->user, [
            'bill_id' => $bill['id'], 'amount' => 40, 'status' => 'failed',
        ]);
        $token = $this->svc->tokens->create($this->user, 'finance', ['money:read', 'money:write']);
        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['token'];
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'add_payment';
        $_SERVER['REQUEST_URI'] = '/api/v1/money/payments';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        putenv('LIFEDASH_TEST_CAPTURE=1');
        try {
            $runner->run(static fn (): array => ['payment' => ['id' => (int) $payment['id']]], 201, true);
            self::fail('expected a captured response');
        } catch (CapturedResponse $e) {
            self::assertSame(201, $e->status);
        }
        $events = $this->svc->audit->search($this->user, [
            'entity' => 'payment',
            'entity_id' => $payment['id'],
            'tool' => 'add_payment',
        ]);
        self::assertNotSame([], $events);
        self::assertSame('payment', $events[0]['detail']['entity']);
    }
}
