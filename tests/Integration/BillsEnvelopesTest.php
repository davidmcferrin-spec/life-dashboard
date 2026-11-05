<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

final class BillsEnvelopesTest extends PgsqlTestCase
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
            new Clock('America/Chicago', new \DateTimeImmutable('2026-11-05 15:00:00', new \DateTimeZone('UTC')))
        );
    }

    public function testPaydayPressureKeepsDebtsAndDoesNotMoveMoney(): void
    {
        $citi = $this->svc->money->createAccount($this->user, [
            'name' => 'Citi', 'kind' => 'checking', 'balance' => 1000, 'last4' => '5917',
        ]);
        $cushion = $this->svc->money->createAccount($this->user, [
            'name' => 'Cushion', 'kind' => 'savings', 'balance' => 400,
        ]);
        $pnc = $this->svc->money->createAccount($this->user, [
            'name' => 'PNC', 'kind' => 'checking', 'balance' => 0,
        ]);
        $this->svc->money->archiveAccount($this->user, (int) $pnc['id']);
        $tesla = $this->svc->plan->saveDebt($this->user, [
            'name' => 'Tesla', 'balance' => 5000, 'minimum_payment' => 450, 'due_day' => 10, 'apr_percent' => 6,
        ]);
        $this->svc->plan->saveDebt($this->user, [
            'name' => 'Midland', 'balance' => 800, 'minimum_payment' => 40, 'due_day' => 12, 'apr_percent' => 0,
        ]);
        $this->svc->bills->savePayday($this->user, [
            'cadence' => 'biweekly',
            'next_on' => '2026-11-13',
            'paycheck_amount' => 2000,
            'living_float' => 200,
        ]);

        $mortgage = $this->svc->bills->saveBill($this->user, [
            'name' => 'Mortgage', 'amount' => 1200, 'cadence' => 'monthly', 'due_day' => 1,
            'pay_from_account_id' => $citi['id'], 'autopay' => true,
        ]);
        $teslaBill = $this->svc->bills->saveBill($this->user, [
            'name' => 'Tesla', 'amount' => 450, 'cadence' => 'monthly', 'due_day' => 10,
            'pay_from_account_id' => $citi['id'], 'autopay' => true, 'debt_id' => $tesla['id'],
        ]);
        $this->svc->bills->saveBill($this->user, [
            'name' => 'Electric', 'amount' => 80, 'cadence' => 'biweekly', 'anchor_on' => '2026-10-30',
            'pay_from_label' => 'Citi 5917',
        ]);
        $this->svc->bills->saveBill($this->user, [
            'name' => 'Prime', 'amount' => 16, 'cadence' => 'monthly', 'due_day' => 3,
            'cancelled_charging' => true,
        ]);
        $capOne = $this->svc->bills->saveBill($this->user, [
            'name' => 'Cap One', 'amount' => 0, 'cadence' => 'monthly', 'due_day' => 8,
            'statement_min' => 35, 'autopay' => true,
        ]);
        $gym = $this->svc->bills->saveBill($this->user, [
            'name' => 'Old Gym', 'amount' => 10, 'cadence' => 'monthly', 'due_day' => 20,
            'pay_from_account_id' => $pnc['id'], 'autopay' => true,
        ]);

        $trip = $this->svc->bills->saveEnvelope($this->user, [
            'name' => 'WDW', 'job_type' => 'trip', 'target_amount' => 500, 'funded_amount' => 100,
            'target_on' => '2026-10-01',
        ]);
        $buffer = $this->svc->bills->saveEnvelope($this->user, [
            'name' => 'Cushion', 'job_type' => 'buffer', 'target_amount' => 1000, 'funded_amount' => 50,
            'account_id' => $cushion['id'], 'target_on' => '2026-12-26',
        ]);

        self::assertTrue($mortgage['in_cycle'] || $mortgage['status'] === 'past_due');
        self::assertSame('past_due', $mortgage['status']);
        self::assertTrue($teslaBill['payment_event']);
        self::assertSame('Tesla', $teslaBill['debt_name']);
        self::assertTrue($capOne['uses_statement_min']);
        self::assertSame(35.0, $capOne['pull_amount']);
        self::assertContains('closed_account', $gym['flags']);
        self::assertContains('cancelled_charging', $this->svc->bills->bill($this->user, (int) $this->idOf('Prime'))['flags']);

        self::assertSame('account', $buffer['funded_source']);
        self::assertSame(400.0, $buffer['funded']);
        self::assertSame(50.0, $buffer['funded_amount']);
        self::assertSame(40.0, $buffer['percent']);
        self::assertNull($buffer['on_track']);
        self::assertFalse($trip['on_track']);
        self::assertSame('manual', $trip['funded_source']);

        $pressure = $this->svc->bills->pressure($this->user);
        self::assertSame('2026-11-13', $pressure['next_payday']);
        self::assertSame(2356.0, $pressure['pulls_total']);
        self::assertSame(40.0, $pressure['debt_total']);
        self::assertSame(-596.0, $pressure['unassigned']);
        self::assertFalse($pressure['moves_money']);
        self::assertSame(['Midland'], array_column($pressure['debt_minimums'], 'name'));
        $electric = array_values(array_filter($pressure['bills'], static fn (array $line): bool => $line['name'] === 'Electric'));
        self::assertCount(2, $electric);
        self::assertNotContains('Tesla', array_column($pressure['debt_minimums'], 'name'));

        self::assertSame(5000.0, $this->debtBalance('Tesla'));
        self::assertStringContainsString('Weekly food is not included', (string) $pressure['formula']);

        $beforeTx = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_transactions WHERE user_id = ?', [$this->user]);
        $proposal = $this->svc->bills->propose($this->user, ['envelope_id' => $trip['id'], 'amount' => 25]);
        self::assertFalse($proposal['moves_money']);
        self::assertSame('proposed', $proposal['proposal']['status']);
        self::assertSame(-621.0, $proposal['proposal']['unassigned_after']);
        self::assertSame(100.0, $this->svc->bills->envelope($this->user, (int) $trip['id'])['funded']);
        self::assertSame(400.0, (float) $this->db->fetchScalar('SELECT balance FROM money_accounts WHERE id = ?', [(int) $cushion['id']]));

        $paid = $this->svc->bills->markCycle($this->user, (int) $mortgage['id'], [
            'status' => 'paid', 'cycle_due_on' => '2026-11-01',
        ]);
        self::assertSame('paid', $paid['status']);
        self::assertSame('2026-12-01', $paid['next_due_on']);
        self::assertSame($beforeTx, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM money_transactions WHERE user_id = ?', [$this->user]));
        self::assertSame(1000.0, (float) $this->db->fetchScalar('SELECT balance FROM money_accounts WHERE id = ?', [(int) $citi['id']]));
        $after = $this->svc->bills->pressure($this->user);
        self::assertSame(604.0, $after['unassigned']);
        self::assertNotContains('Mortgage', array_column($after['bills'], 'name'));

        $manual = $this->svc->bills->updateFunded($this->user, (int) $buffer['id'], ['funded_amount' => 10]);
        self::assertSame(400.0, $manual['funded']);
        self::assertSame(10.0, $manual['funded_amount']);
        self::assertStringContainsString('No money was moved', (string) $manual['funded_note']);
        self::assertSame(400.0, (float) $this->db->fetchScalar('SELECT balance FROM money_accounts WHERE id = ?', [(int) $cushion['id']]));

        $due = $this->svc->plan->billsDue($this->user);
        $teslaRows = array_values(array_filter($due, static fn (array $row): bool => $row['name'] === 'Tesla'));
        self::assertCount(1, $teslaRows);
        self::assertSame('bill', $teslaRows[0]['kind']);

        $this->svc->bills->archiveBill($this->user, (int) $gym['id']);
        $names = array_column($this->svc->bills->bills($this->user)['bills'], 'name');
        self::assertNotContains('Old Gym', $names);

        try {
            $this->svc->bills->saveEnvelope($this->user, [
                'name' => 'Second cushion', 'account_id' => $cushion['id'],
            ]);
            self::fail('expected one account per envelope');
        } catch (ValidationException $e) {
            self::assertStringContainsString('already linked', $e->getMessage());
        }
        try {
            $this->svc->bills->saveBill($this->user, [
                'name' => '123456789012', 'amount' => 1, 'cadence' => 'monthly', 'due_day' => 2,
            ]);
            self::fail('expected a full account number to be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('account number', strtolower($e->getMessage()));
        }
    }

    private function idOf(string $name): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT id FROM money_bills WHERE user_id = ? AND name = ?',
            [$this->user, $name]
        );
    }

    private function debtBalance(string $name): float
    {
        return (float) $this->db->fetchScalar(
            'SELECT balance FROM money_debts WHERE user_id = ? AND name = ?',
            [$this->user, $name]
        );
    }
}
