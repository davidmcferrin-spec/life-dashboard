<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Auth\Auth;
use Dashboard\Money\AccountNumberGuard;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

final class MoneyAgentTest extends PgsqlTestCase
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
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LIFEDASH_TOOL'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_GET);
        parent::tearDown();
    }

    public function testDebtLinksAvailableCreditAndDoesNotDoubleCount(): void
    {
        $card = $this->svc->money->createAccount($this->user, [
            'name' => 'Visa', 'kind' => 'credit', 'balance' => 500, 'credit_limit' => 2000, 'last4' => '4242',
        ]);
        self::assertEqualsWithDelta(1500.0, (float) $card['available_credit'], 0.01);

        $stored = $this->svc->money->updateAccount($this->user, (int) $card['id'], ['available_credit' => 1000]);
        self::assertEqualsWithDelta(1000.0, (float) $stored['available_credit'], 0.01);
        $kept = $this->svc->money->updateAccount($this->user, (int) $card['id'], ['name' => 'Visa card']);
        self::assertEqualsWithDelta(1000.0, (float) $kept['available_credit'], 0.01);

        $debt = $this->svc->plan->saveDebt($this->user, [
            'name' => 'Visa debt', 'last4' => '****4242', 'balance' => 400, 'apr_percent' => 19.9,
            'minimum_payment' => 40, 'due_day' => 12, 'notes' => 'snowball', 'credit_limit' => 2000,
        ]);
        self::assertSame((int) $card['id'], $debt['account_id']);
        self::assertSame('4242', $debt['last4']);
        self::assertSame('snowball', $debt['notes']);
        self::assertEqualsWithDelta(2000.0, (float) $debt['credit_limit'], 0.01);

        $credit = $this->svc->plan->credit($this->user);
        self::assertSame('limits', $credit['utilization_source']);
        self::assertEqualsWithDelta(20.0, (float) $credit['utilization_percent'], 0.01);

        $other = $this->svc->money->createAccount($this->user, [
            'name' => 'Spare', 'kind' => 'credit', 'balance' => 0, 'last4' => '1111',
        ]);
        $unlinked = $this->svc->plan->saveDebt($this->user, [
            'name' => 'Store', 'last4' => '1111', 'balance' => 50, 'account_id' => null,
        ]);
        self::assertNull($unlinked['account_id']);

        $this->svc->money->createAccount($this->user, [
            'name' => 'Twin A', 'kind' => 'credit', 'balance' => 0, 'last4' => '9999',
        ]);
        $this->svc->money->createAccount($this->user, [
            'name' => 'Twin B', 'kind' => 'credit', 'balance' => 0, 'last4' => '9999',
        ]);
        $ambiguous = $this->svc->plan->saveDebt($this->user, [
            'name' => 'Spare card', 'last4' => '9999', 'balance' => 10, 'credit_limit' => 500,
        ]);
        self::assertNull($ambiguous['account_id']);

        $waiting = $this->svc->plan->saveDebt($this->user, [
            'name' => 'Amex', 'last4' => '8888', 'balance' => 25, 'credit_limit' => 500,
        ]);
        self::assertNull($waiting['account_id']);
        $amex = $this->svc->money->createAccount($this->user, [
            'name' => 'Amex card', 'kind' => 'credit', 'balance' => 25, 'last4' => '8888',
        ]);
        self::assertEqualsWithDelta(500.0, (float) $amex['credit_limit'], 0.01);
        $amexDebt = null;
        foreach ($this->svc->plan->debts($this->user) as $row) {
            if ($row['name'] === 'Amex') {
                $amexDebt = $row;
            }
        }
        self::assertNotNull($amexDebt);
        self::assertSame((int) $amex['id'], $amexDebt['account_id']);

        $moved = $this->svc->plan->updateDebt($this->user, (int) $unlinked['id'], ['account_id' => (int) $other['id']]);
        self::assertSame((int) $other['id'], $moved['account_id']);
        $cleared = $this->svc->plan->updateDebt($this->user, (int) $moved['id'], ['account_id' => null]);
        self::assertNull($cleared['account_id']);

        try {
            $this->svc->plan->saveDebt($this->user, ['name' => 'Bad', 'notes' => 'acct 123456789012']);
            self::fail('expected a full account number to be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('last 4', strtolower($e->getMessage()));
        }
        try {
            $this->svc->plan->saveDebt($this->user, ['name' => 'Bad last4', 'last4' => '12345']);
            self::fail('expected a long last4 to be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('last4', $e->fields());
        }

        $archived = $this->svc->plan->archiveDebt($this->user, (int) $debt['id']);
        self::assertTrue($archived['archived']);
        $names = array_column($this->svc->plan->debts($this->user), 'name');
        self::assertNotContains('Visa debt', $names);

        $gone = $this->svc->plan->saveDebt($this->user, ['name' => 'Temp', 'balance' => 1]);
        $this->db->pdo()->exec(
            'CREATE TABLE money_debt_hold (id BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, debt_id BIGINT REFERENCES money_debts(id))'
        );
        $this->db->execute('INSERT INTO money_debt_hold (debt_id) VALUES (?)', [(int) $gone['id']]);
        try {
            $this->svc->plan->deleteDebt($this->user, (int) $gone['id']);
            self::fail('expected a dependent row to block delete');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Archive', $e->getMessage());
        }
        $this->db->execute('DELETE FROM money_debt_hold WHERE debt_id = ?', [(int) $gone['id']]);
        $this->svc->plan->deleteDebt($this->user, (int) $gone['id']);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_debts WHERE id = ?', [(int) $gone['id']]));
    }

    public function testCapsSinkingFundsAndReadOnlyReview(): void
    {
        $this->svc->plan->setCategoryCap($this->user, 'Food', 275, null, '2026-10-19');
        $food = $this->svc->plan->foodWeek($this->user);
        self::assertSame(250.0, $food['cap']);
        self::assertSame('2026-10-18', $food['to']);
        self::assertEqualsWithDelta(275.0, (float) $this->svc->plan->weeklyCap($this->user, 'Food', '2026-10-19'), 0.01);

        $this->svc->plan->setCategoryCap($this->user, 'Food', null, 800, '2026-11-01');
        self::assertEqualsWithDelta(275.0, (float) $this->svc->plan->weeklyCap($this->user, 'Food', '2026-11-02'), 0.01);
        self::assertEqualsWithDelta(800.0, (float) $this->svc->plan->monthlyCap($this->user, 'Food', '2026-11-02'), 0.01);

        $fund = $this->svc->plan->saveSinkingFund($this->user, [
            'name' => 'Tires', 'target_amount' => 400, 'notes' => 'winter',
        ]);
        self::assertSame(0.0, $fund['balance']);
        self::assertSame('winter', $fund['notes']);
        $updated = $this->svc->plan->updateSinkingFund($this->user, (int) $fund['id'], ['notes' => 'snow']);
        self::assertSame('snow', $updated['notes']);
        $archived = $this->svc->plan->archiveSinkingFund($this->user, (int) $fund['id']);
        self::assertTrue($archived['archived']);
        self::assertSame([], $this->svc->plan->sinkingFunds($this->user));

        $before = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM pages WHERE user_id = ?', [$this->user]);
        $draft = $this->svc->money->weeklyReview($this->user, false);
        self::assertFalse($draft['written']);
        self::assertStringContainsString('Weekly review', $draft['body']);
        self::assertSame($before, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM pages WHERE user_id = ?', [$this->user]));
        $page = $this->svc->money->weeklyReview($this->user, true);
        self::assertTrue($page['written']);
        self::assertSame('review', $page['kind']);
    }

    public function testAuditLogAndTodoToken(): void
    {
        $debt = $this->svc->plan->saveDebt($this->user, ['name' => 'Card', 'balance' => 20, 'notes' => 'keep']);
        $money = $this->svc->tokens->create($this->user, 'finance', ['money:read', 'money:write']);
        $todos = $this->svc->tokens->create($this->user, 'tasks', ['todos:read', 'todos:write']);
        self::assertNull($todos['expires_at']);
        self::assertTrue(\Dashboard\Auth\ApiTokenService::isTodosOnly($todos['scopes']));

        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $money['token'];
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'create_debt';
        $_SERVER['REQUEST_URI'] = '/api/v1/money/debts';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        putenv('LIFEDASH_TEST_CAPTURE=1');
        try {
            $runner->run(static fn (int $u): array => [
                'debt' => ['id' => (int) $debt['id'], 'notes' => 'secret 123456789012', 'name' => 'Card'],
            ], 201, true);
            self::fail('expected a captured response');
        } catch (CapturedResponse $e) {
            self::assertSame(201, $e->status);
        }

        $events = $this->svc->audit->search($this->user, ['entity' => 'debt', 'entity_id' => $debt['id'], 'tool' => 'create_debt']);
        self::assertNotSame([], $events);
        $blob = json_encode($events[0]);
        self::assertIsString($blob);
        self::assertStringNotContainsString('123456789012', $blob);
        self::assertStringNotContainsString('secret', $blob);
        self::assertStringNotContainsString('keep', $blob);
        self::assertSame('debt', $events[0]['detail']['entity']);
        self::assertSame((int) $debt['id'], (int) $events[0]['detail']['entity_id']);

        try {
            Scope::auditEntityAllow($money['scopes'], 'todo');
            self::fail('expected a money token to be refused');
        } catch (ForbiddenException) {
            self::assertTrue(true);
        }
        $onlyMoney = $this->svc->audit->search(
            $this->user,
            [],
            Scope::auditEntityAllow($money['scopes'], null)
        );
        self::assertNotSame([], $onlyMoney);
    }

    public function testTodosLandOnTheDashboard(): void
    {
        $cats = $this->svc->todos->agentCategories($this->user);
        $names = array_column($cats, 'name');
        self::assertContains('Work', $names);
        self::assertContains('Personal', $names);
        self::assertContains('Home', $names);

        $made = $this->svc->todos->agentCreateCategory($this->user, 'Travel');
        self::assertSame('Travel', $made['name']);

        $todo = $this->svc->todos->agentCreate($this->user, [
            'title' => 'Pack cables', 'category' => 'Travel', 'due_date' => '2026-10-20',
            'notes' => 'XLR', 'priority' => 1,
        ]);
        self::assertSame('Travel', $todo['category']);
        self::assertSame(1, $todo['priority']);
        self::assertSame('XLR', $todo['notes']);

        $open = $this->svc->todos->agentList($this->user, [
            'category' => 'Travel', 'status' => 'open', 'priority' => 1, 'due_before' => '2026-10-20', 'due_after' => '2026-10-20',
        ]);
        self::assertSame('Pack cables', $open[0]['title']);

        $moved = $this->svc->todos->agentUpdate($this->user, (int) $todo['id'], [
            'category' => 'Work', 'priority' => 3, 'notes' => 'later',
        ]);
        self::assertSame('Work', $moved['category']);
        self::assertSame(3, $moved['priority']);

        $widget = $this->svc->todos->widgetPayload($this->user);
        $titles = [];
        foreach ($widget['tab_lists'] as $list) {
            if ($list['name'] !== 'Work') {
                continue;
            }
            $payload = $this->svc->todos->widgetPayload($this->user, (int) $list['id']);
            $titles = array_column($payload['items'], 'title');
        }
        self::assertContains('Pack cables', $titles);

        $done = $this->svc->todos->agentComplete($this->user, (int) $todo['id'], false);
        self::assertSame('completed', $done['status']);
        $again = $this->svc->todos->agentComplete($this->user, (int) $todo['id'], true);
        self::assertSame('open', $again['status']);

        try {
            $this->svc->todos->agentCreate($this->user, ['title' => 'Nope', 'category' => 'Missing']);
            self::fail('expected an unknown category to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('create_todo_category', $e->getMessage());
        }
    }
}
