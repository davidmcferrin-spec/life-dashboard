<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Auth\Auth;
use Dashboard\Money\AccountNumberGuard;
use Dashboard\Money\BudgetImporter;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

final class MoneyPlanTest extends PgsqlTestCase
{
    private int $user;

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
        $this->svc = new Services(
            $this->db,
            new Clock('America/Chicago', new \DateTimeImmutable('2026-10-14 15:00:00', new \DateTimeZone('UTC')))
        );
    }

    protected function tearDown(): void
    {
        putenv('LIFEDASH_TEST_CAPTURE');
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LIFEDASH_TOOL'], $_SERVER['REQUEST_URI'], $_GET);
        parent::tearDown();
    }

    public function testImportAliasesTheCreatorNameAndRefusesAccountNumbers(): void
    {
        $root = dirname(__DIR__) . '/fixtures/budget';
        $importer = new BudgetImporter($this->db, $this->svc->money, $this->svc->plan, $this->svc->pages);
        $counts = $importer->importDirectory($this->user, $root . '/good', 'StreamCo');

        self::assertSame(1, $counts['accounts']);
        self::assertSame(1, $counts['aliased']);
        self::assertSame(1, $counts['debts']);
        self::assertSame(1, $counts['sinking_funds']);
        self::assertSame(1, $counts['snapshots']);
        self::assertSame(1, $counts['credit_scores']);
        self::assertSame(1, $counts['retirement']);
        self::assertSame(1, $counts['notes']);
        self::assertSame(24, $counts['budget_lines']);
        self::assertSame(5, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_transactions WHERE user_id = ?', [$this->user]));

        $payees = $this->db->fetchAll('SELECT payee, note FROM money_transactions WHERE user_id = ?', [$this->user]);
        $blob = json_encode($payees);
        self::assertIsString($blob);
        self::assertStringNotContainsString('StreamCo', $blob);
        self::assertStringContainsString(AccountNumberGuard::CREATOR, $blob);

        $page = (string) $this->db->fetchScalar("SELECT body FROM pages WHERE user_id = ? AND slug = 'budget-notes'", [$this->user]);
        self::assertStringContainsString('weekly food cap', $page);
        self::assertStringNotContainsString('StreamCo', $page);

        $debts = $this->svc->plan->debts($this->user);
        self::assertSame('Tesla', $debts[0]['name']);
        self::assertSame('1234', $debts[0]['last4']);
        self::assertSame('2027-12-31', $debts[0]['target_payoff_on']);
        self::assertNotNull($debts[0]['estimated_payoff_on']);
        self::assertNotSame('2027-12-31', $debts[0]['estimated_payoff_on']);

        $this->svc->plan->updateDebt($this->user, (int) $debts[0]['id'], ['due_day' => 20]);
        $food = $this->svc->plan->foodWeek($this->user);
        self::assertSame(250.0, $food['cap']);
        self::assertSame(340.0, $food['spent']);
        self::assertTrue($food['over']);

        $bills = $this->svc->plan->billsDue($this->user);
        $names = array_column($bills, 'name');
        self::assertContains('Rent', $names);
        self::assertContains('Tesla', $names);

        $retirement = $this->svc->plan->retirement($this->user);
        self::assertEqualsWithDelta(0.15, $retirement['contribution_rate'], 0.0001);
        self::assertTrue($retirement['on_track']);
        self::assertSame(2, $this->svc->plan->babyStep($this->user)['step']);
        self::assertSame('Debt snowball', $this->svc->plan->babyStep($this->user)['name']);

        $credit = $this->svc->plan->credit($this->user);
        self::assertSame(740, $credit['score']);
        self::assertSame('stored', $credit['utilization_source']);
        self::assertEqualsWithDelta(22.5, $credit['utilization_percent'], 0.01);

        $card = $this->svc->money->createAccount($this->user, [
            'name' => 'Visa', 'kind' => 'credit', 'balance' => 500, 'credit_limit' => 2000,
        ]);
        self::assertSame('credit', $card['kind']);
        $computed = $this->svc->plan->credit($this->user);
        self::assertSame('limits', $computed['utilization_source']);
        self::assertEqualsWithDelta(25.0, $computed['utilization_percent'], 0.01);

        $dash = $this->svc->plan->dashboard($this->user);
        self::assertArrayHasKey('net_worth', $dash);
        self::assertArrayHasKey('bills_due', $dash);
        self::assertSame(0.15, $dash['retirement']['target']);

        foreach (['baby_step', 'debt_snowball', 'food_week', 'budget_actual', 'sinking_funds', 'bills_due', 'retirement', 'credit', 'networth'] as $type) {
            $widget = $this->svc->widgets->create($this->user, $type, $type);
            $payload = $this->svc->widgetData->forWidget($this->user, (int) $widget['id']);
            self::assertSame([], $payload['metric_ids'], $type);
        }
        $foodWidget = $this->svc->widgets->create($this->user, 'food_week', 'Food week');
        $foodPayload = $this->svc->widgetData->forWidget($this->user, (int) $foodWidget['id']);
        self::assertTrue($foodPayload['over']);

        try {
            $importer->importDirectory($this->user, $root . '/account-number', 'StreamCo');
            self::fail('expected a full account number to be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('full account number', $e->getMessage());
        }
        self::assertStringNotContainsString('123456789012', (string) json_encode(
            $this->db->fetchAll('SELECT payee, note FROM money_transactions WHERE user_id = ?', [$this->user])
        ));

        try {
            $importer->importDirectory($this->user, $root . '/bad-headers', '');
            self::fail('expected a header mismatch');
        } catch (ValidationException $e) {
            self::assertStringContainsString('When', $e->getMessage());
        }

        $mapped = $importer->importDirectory($this->user, $root . '/mapped', '');
        self::assertSame(1, $mapped['transactions']);
        self::assertSame('Lunch', (string) $this->db->fetchScalar(
            "SELECT payee FROM money_transactions WHERE user_id = ? AND payee = 'Lunch'",
            [$this->user]
        ));
    }

    public function testBulkDedupeAndMoneyTokenExpiryAndAudit(): void
    {
        $first = $this->svc->money->addTransactions($this->user, [[
            'date' => '2026-10-13', 'account' => 'Checking', 'amount' => -12.5, 'description' => 'Cafe', 'category' => 'Food',
        ]]);
        $second = $this->svc->money->addTransactions($this->user, [[
            'date' => '2026-10-13', 'account' => ' checking ', 'amount' => -12.50, 'description' => 'Cafe', 'category' => 'Food',
        ]]);
        self::assertSame(1, $first['written']);
        self::assertSame(1, $second['written']);
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_transactions WHERE user_id = ?', [$this->user]));

        $money = $this->svc->tokens->create($this->user, 'finance', ['money:read', 'money:write']);
        $expires = new \DateTimeImmutable((string) $money['expires_at'], new \DateTimeZone('UTC'));
        $days = ($expires->getTimestamp() - time()) / 86400;
        self::assertGreaterThan(89, $days);
        self::assertLessThan(91, $days);

        $picked = $this->svc->tokens->create($this->user, 'until january', ['money:read'], '2027-01-15');
        self::assertStringStartsWith('2027-01-15', (string) $picked['expires_at']);
        $open = $this->svc->tokens->create($this->user, 'everything', ['*']);
        self::assertNull($open['expires_at']);

        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $picked['token'];
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'list_transactions';
        $_SERVER['REQUEST_URI'] = '/api/v1/money/transactions?from=2026-10-01&category=Food&account=123456789012';
        $_GET = ['from' => '2026-10-01', 'category' => 'Food', 'account' => '123456789012'];
        putenv('LIFEDASH_TEST_CAPTURE=1');
        try {
            $runner->run(static fn (int $u): array => ['transactions' => []], 200, false);
            self::fail('expected a captured response');
        } catch (CapturedResponse $e) {
            self::assertSame(200, $e->status);
        }
        $audit = $this->db->fetchOne(
            "SELECT action, tool, detail::text AS detail FROM audit_events WHERE user_id = ? AND action = 'GET'",
            [$this->user]
        );
        self::assertNotNull($audit);
        self::assertSame('list_transactions', $audit['tool']);
        self::assertStringContainsString('category=Food', (string) $audit['detail']);
        self::assertStringNotContainsString('123456789012', (string) $audit['detail']);

        try {
            $runner->run(static fn (int $u): array => ['ok' => true], 200, true);
            self::fail('expected a read token to be refused');
        } catch (CapturedResponse $e) {
            self::assertSame(403, $e->status);
        }
    }
}
