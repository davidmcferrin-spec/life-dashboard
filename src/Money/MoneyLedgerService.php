<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Payments, inflows, benefit pots, category overrides, and the combined
 * money summary. Writes do not change a transaction amount or a debt balance.
 */
final class MoneyLedgerService
{
    /** @var list<string> */
    private const PAYMENT_STATUSES = ['scheduled', 'pending', 'posted', 'failed'];

    /** @var list<string> */
    private const INFLOW_KINDS = ['reimbursement', 'paycheck', 'refund', 'other'];

    /** @var list<string> */
    private const INFLOW_STATUSES = ['expected', 'received', 'cancelled'];

    /** @var list<string> */
    private const POT_KINDS = ['fsa', 'hsa'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly MoneyService $money,
        private readonly MoneyPlanService $plan,
        private readonly ?PlaidSync $plaid = null,
    ) {}

    /**
     * Ask for a live sync. This install has no bank connector, so the result
     * says so and leaves the stored timestamps alone.
     *
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function refreshAccounts(int $userId, ?int $accountId): array
    {
        if ($this->plaid !== null) {
            return $this->plaid->refresh($userId, $accountId);
        }
        $accounts = $accountId === null
            ? $this->money->accounts($userId)
            : [$this->money->account($userId, $accountId)];
        $out = [];
        foreach ($accounts as $account) {
            $out[] = [
                'id'             => (int) $account['id'],
                'name'           => (string) $account['name'],
                'last_synced_at' => $account['last_synced_at'],
                'stale'          => (bool) $account['stale'],
                'refreshed'      => false,
                'live_sync'      => false,
                'message'        => 'No live sync is connected for this account.',
            ];
        }

        return ['accounts' => $out];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function setDebtComponents(int $userId, int $debtId, array $input): array
    {
        $debt = $this->plan->debt($userId, $debtId);
        $current = $this->componentRow($userId, $debtId);
        $next = [];
        foreach (DebtComponents::KINDS as $kind) {
            if (array_key_exists($kind, $input)) {
                $next[$kind] = $this->optionalNonNegative($input[$kind], $kind);
            } else {
                $next[$kind] = $current[$kind] ?? null;
            }
        }
        $empty = true;
        foreach ($next as $amount) {
            if ($amount !== null) {
                $empty = false;
                break;
            }
        }
        if ($empty) {
            $this->db->execute(
                'DELETE FROM money_debt_components WHERE debt_id = ? AND user_id = ?',
                [$debtId, $userId]
            );
        } else {
            $this->db->execute(
                'INSERT INTO money_debt_components
                    (debt_id, user_id, principal, unapplied_funds, late_charges, fees, interest)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (debt_id) DO UPDATE SET
                    principal = EXCLUDED.principal,
                    unapplied_funds = EXCLUDED.unapplied_funds,
                    late_charges = EXCLUDED.late_charges,
                    fees = EXCLUDED.fees,
                    interest = EXCLUDED.interest,
                    updated_at = (now() AT TIME ZONE \'UTC\')',
                [$debtId, $userId, $next['principal'], $next['unapplied_funds'], $next['late_charges'], $next['fees'], $next['interest']]
            );
        }
        $fresh = $this->plan->debt($userId, $debtId);
        $fresh['balance_unchanged'] = abs((float) $fresh['balance'] - (float) $debt['balance']) < 0.001;

        return $fresh;
    }

    /**
     * @return array<string, mixed>
     */
    public function debtComponents(int $userId, int $debtId): array
    {
        return $this->plan->debt($userId, $debtId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addPayment(int $userId, array $input): array
    {
        $billId = $this->optionalId($input['bill_id'] ?? null, 'bill_id');
        $debtId = $this->optionalId($input['debt_id'] ?? null, 'debt_id');
        $this->requireTarget($userId, $billId, $debtId);
        $amount = $this->nonNegative($input['amount'] ?? null, 'amount');
        $status = $this->choice($input['status'] ?? 'scheduled', self::PAYMENT_STATUSES, 'status');
        $due = $this->optionalDate($input['due_on'] ?? null, 'due_on');
        $note = $this->note($input['note'] ?? null);
        $txn = $this->optionalTransaction($userId, $input['transaction_id'] ?? null);
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO money_payments (user_id, bill_id, debt_id, amount, status, due_on, note, transaction_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$userId, $billId, $debtId, $amount, $status, $due, $note, $txn]
        );

        return $this->payment($userId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updatePayment(int $userId, int $id, array $input): array
    {
        $row = $this->paymentRow($userId, $id);
        $billId = array_key_exists('bill_id', $input) ? $this->optionalId($input['bill_id'], 'bill_id') : $this->storedId($row['bill_id']);
        $debtId = array_key_exists('debt_id', $input) ? $this->optionalId($input['debt_id'], 'debt_id') : $this->storedId($row['debt_id']);
        $this->requireTarget($userId, $billId, $debtId);
        $amount = array_key_exists('amount', $input) ? $this->nonNegative($input['amount'], 'amount') : (float) $row['amount'];
        $status = array_key_exists('status', $input) ? $this->choice($input['status'], self::PAYMENT_STATUSES, 'status') : (string) $row['status'];
        $due = array_key_exists('due_on', $input) ? $this->optionalDate($input['due_on'], 'due_on') : $this->dateOrNull($row['due_on']);
        $note = array_key_exists('note', $input) ? $this->note($input['note']) : ($row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null);
        $txn = array_key_exists('transaction_id', $input) ? $this->optionalTransaction($userId, $input['transaction_id']) : $this->storedId($row['transaction_id']);
        $this->db->execute(
            'UPDATE money_payments SET bill_id = ?, debt_id = ?, amount = ?, status = ?, due_on = ?, note = ?, transaction_id = ?,
                updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            [$billId, $debtId, $amount, $status, $due, $note, $txn, $id, $userId]
        );

        return $this->payment($userId, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function payments(int $userId, ?string $status = null, ?int $billId = null, ?int $debtId = null): array
    {
        $sql = 'SELECT * FROM money_payments WHERE user_id = ?';
        $params = [$userId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $this->choice($status, self::PAYMENT_STATUSES, 'status');
        }
        if ($billId !== null) {
            $sql .= ' AND bill_id = ?';
            $params[] = $billId;
        }
        if ($debtId !== null) {
            $sql .= ' AND debt_id = ?';
            $params[] = $debtId;
        }
        $sql .= ' ORDER BY due_on NULLS LAST, id DESC';

        return array_map(fn (array $row): array => $this->formatPayment($row), $this->db->fetchAll($sql, $params));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addInflow(int $userId, array $input): array
    {
        $kind = $this->choice($input['kind'] ?? 'other', self::INFLOW_KINDS, 'kind');
        $source = $this->label($input['source'] ?? null, 'source', 160);
        $amount = $this->nonNegative($input['amount'] ?? null, 'amount');
        $expected = $this->money->cleanDate($input['expected_on'] ?? null);
        $status = $this->choice($input['status'] ?? 'expected', self::INFLOW_STATUSES, 'status');
        $note = $this->note($input['note'] ?? null);
        $txn = $this->optionalTransaction($userId, $input['transaction_id'] ?? null);
        $received = $status === 'received' ? ($this->optionalDate($input['received_on'] ?? null, 'received_on') ?? $this->clock->today()) : $this->optionalDate($input['received_on'] ?? null, 'received_on');
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO money_expected_inflows
                (user_id, kind, source, amount, expected_on, status, transaction_id, received_on, note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [$userId, $kind, $source, $amount, $expected, $status, $txn, $received, $note]
        );

        return $this->inflow($userId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateInflow(int $userId, int $id, array $input): array
    {
        $row = $this->inflowRow($userId, $id);
        $kind = array_key_exists('kind', $input) ? $this->choice($input['kind'], self::INFLOW_KINDS, 'kind') : (string) $row['kind'];
        $source = array_key_exists('source', $input) ? $this->label($input['source'], 'source', 160) : (string) $row['source'];
        $amount = array_key_exists('amount', $input) ? $this->nonNegative($input['amount'], 'amount') : (float) $row['amount'];
        $expected = array_key_exists('expected_on', $input) ? $this->money->cleanDate($input['expected_on']) : substr((string) $row['expected_on'], 0, 10);
        $status = array_key_exists('status', $input) ? $this->choice($input['status'], self::INFLOW_STATUSES, 'status') : (string) $row['status'];
        $note = array_key_exists('note', $input) ? $this->note($input['note']) : ($row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null);
        $txn = array_key_exists('transaction_id', $input) ? $this->optionalTransaction($userId, $input['transaction_id']) : $this->storedId($row['transaction_id']);
        $received = array_key_exists('received_on', $input) ? $this->optionalDate($input['received_on'], 'received_on') : $this->dateOrNull($row['received_on']);
        $this->db->execute(
            'UPDATE money_expected_inflows SET kind = ?, source = ?, amount = ?, expected_on = ?, status = ?,
                transaction_id = ?, received_on = ?, note = ?, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            [$kind, $source, $amount, $expected, $status, $txn, $received, $note, $id, $userId]
        );

        return $this->inflow($userId, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function inflows(int $userId, ?string $status = null): array
    {
        $sql = 'SELECT * FROM money_expected_inflows WHERE user_id = ?';
        $params = [$userId];
        if ($status !== null && $status !== '') {
            $sql .= ' AND status = ?';
            $params[] = $this->choice($status, self::INFLOW_STATUSES, 'status');
        }
        $sql .= ' ORDER BY expected_on ASC, id ASC';

        return array_map(fn (array $row): array => $this->formatInflow($row), $this->db->fetchAll($sql, $params));
    }

    /**
     * Marks the inflow received and may point at a transaction. The transaction
     * row itself is not updated.
     *
     * @return array<string, mixed>
     */
    public function markInflowReceived(int $userId, int $id, ?int $transactionId, ?string $receivedOn): array
    {
        $before = $this->inflowRow($userId, $id);
        $txn = $this->optionalTransaction($userId, $transactionId);
        $day = $receivedOn !== null && $receivedOn !== '' ? $this->money->cleanDate($receivedOn) : $this->clock->today();
        $snapshot = $txn !== null ? $this->transactionSnapshot($userId, $txn) : null;
        $this->db->execute(
            'UPDATE money_expected_inflows SET status = ?, transaction_id = ?, received_on = ?,
                updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            ['received', $txn, $day, $id, $userId]
        );
        if ($snapshot !== null) {
            $after = $this->transactionSnapshot($userId, $txn);
            if ($after !== $snapshot) {
                throw new \RuntimeException('Marking an inflow received changed the transaction.');
            }
        }
        $inflow = $this->inflow($userId, $id);
        $inflow['previous_status'] = (string) $before['status'];

        return $inflow;
    }

    /**
     * @return array<string, mixed>
     */
    public function creditUtilization(int $userId): array
    {
        return $this->plan->creditUtilization($userId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function benefitPots(int $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM money_benefit_pots WHERE user_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY deadline NULLS LAST, name ASC';

        return array_map(fn (array $row): array => $this->formatPot($row), $this->db->fetchAll($sql, [$userId]));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveBenefitPot(int $userId, array $input, ?int $id = null): array
    {
        $existing = $id !== null ? $this->potRow($userId, $id) : null;
        $kind = array_key_exists('kind', $input)
            ? $this->choice($input['kind'], self::POT_KINDS, 'kind')
            : (string) ($existing['kind'] ?? '');
        if ($kind === '') {
            throw ValidationException::withFields(['kind' => 'Kind must be fsa or hsa.']);
        }
        $name = array_key_exists('name', $input) ? $this->label($input['name'], 'name', 80) : (string) ($existing['name'] ?? '');
        $balance = array_key_exists('balance', $input) ? $this->nonNegative($input['balance'], 'balance') : (float) ($existing['balance'] ?? 0);
        $year = array_key_exists('plan_year', $input) ? $this->planYear($input['plan_year']) : (int) ($existing['plan_year'] ?? 0);
        if ($year < 2000) {
            throw ValidationException::withFields(['plan_year' => 'Plan year is required.']);
        }
        $deadline = array_key_exists('deadline', $input) ? $this->optionalDate($input['deadline'], 'deadline') : $this->dateOrNull($existing['deadline'] ?? null);
        $lose = array_key_exists('use_it_or_lose_it', $input)
            ? $this->optionalNonNegative($input['use_it_or_lose_it'], 'use_it_or_lose_it')
            : $this->optionalFloat($existing['use_it_or_lose_it'] ?? null);
        $stamp = $this->clock->utcString($this->clock->now());
        $archive = null;
        if (array_key_exists('archived', $input)) {
            $archive = $this->truth($input['archived']) ? $stamp : null;
        } elseif ($existing !== null && $existing['archived_at'] !== null) {
            $archive = (string) $existing['archived_at'];
        }
        if ($existing === null) {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO money_benefit_pots
                    (user_id, kind, name, balance, plan_year, deadline, use_it_or_lose_it, last_synced_at, archived_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, kind, name, plan_year) DO UPDATE SET
                    balance = EXCLUDED.balance,
                    deadline = EXCLUDED.deadline,
                    use_it_or_lose_it = EXCLUDED.use_it_or_lose_it,
                    last_synced_at = EXCLUDED.last_synced_at,
                    archived_at = EXCLUDED.archived_at,
                    updated_at = (now() AT TIME ZONE \'UTC\')
                 RETURNING id',
                [$userId, $kind, $name, $balance, $year, $deadline, $lose, $stamp, $archive]
            );
        } else {
            $this->db->execute(
                'UPDATE money_benefit_pots SET kind = ?, name = ?, balance = ?, plan_year = ?, deadline = ?,
                    use_it_or_lose_it = ?, last_synced_at = ?, archived_at = ?, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [$kind, $name, $balance, $year, $deadline, $lose, $stamp, $archive, $id, $userId]
            );
        }

        return $this->pot($userId, (int) $id);
    }

    /**
     * Stores an override. The transaction's category column stays as imported.
     * Sticky writes a merchant rule used on later imports.
     *
     * @return array<string, mixed>
     */
    public function setTransactionCategory(int $userId, int $transactionId, string $category, bool $sticky): array
    {
        $txn = $this->money->transaction($userId, $transactionId);
        if ($sticky) {
            $payee = $txn['payee'] ?? null;
            if (!is_string($payee) || trim($payee) === '') {
                throw ValidationException::withFields(['sticky' => 'A sticky rule needs a merchant on the transaction.']);
            }
            AccountNumberGuard::assertNoFullNumber(trim($payee), 'payee');
        }
        $raw = (string) $this->db->fetchScalar(
            'SELECT category FROM money_transactions WHERE id = ? AND user_id = ?',
            [$transactionId, $userId]
        );
        $name = $this->label($category, 'category', 80);
        $categoryId = $this->money->ensureCategory($userId, $name, -1);
        $this->db->execute(
            'INSERT INTO money_category_overrides (user_id, transaction_id, category, category_id)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, transaction_id) DO UPDATE SET
                category = EXCLUDED.category, category_id = EXCLUDED.category_id',
            [$userId, $transactionId, $name, $categoryId]
        );
        $ruleId = null;
        if ($sticky) {
            $payee = trim((string) $txn['payee']);
            $ruleId = (int) $this->db->fetchScalar(
                'INSERT INTO money_merchant_rules (user_id, merchant, category, category_id)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (user_id, lower(merchant)) DO UPDATE SET
                    merchant = EXCLUDED.merchant,
                    category = EXCLUDED.category,
                    category_id = EXCLUDED.category_id,
                    updated_at = (now() AT TIME ZONE \'UTC\')
                 RETURNING id',
                [$userId, trim($payee), $name, $categoryId]
            );
        }
        $kept = (string) $this->db->fetchScalar(
            'SELECT category FROM money_transactions WHERE id = ? AND user_id = ?',
            [$transactionId, $userId]
        );
        if ($kept !== $raw) {
            throw new \RuntimeException('Setting a category changed the stored category.');
        }
        $fresh = $this->money->transaction($userId, $transactionId);
        $fresh['sticky'] = $sticky;
        $fresh['merchant_rule_id'] = $ruleId;

        return $fresh;
    }

    /**
     * Removes the override. The imported category remains. drop_rule also
     * removes the merchant rule for this payee.
     *
     * @return array<string, mixed>
     */
    public function revertTransactionCategory(int $userId, int $transactionId, bool $dropRule): array
    {
        $txn = $this->money->transaction($userId, $transactionId);
        $removed = $this->db->execute(
            'DELETE FROM money_category_overrides WHERE user_id = ? AND transaction_id = ?',
            [$userId, $transactionId]
        );
        if ($removed < 1) {
            throw ValidationException::withFields(['transaction_id' => 'There is no category override on this transaction.']);
        }
        if ($dropRule && is_string($txn['payee'] ?? null) && trim((string) $txn['payee']) !== '') {
            $this->db->execute(
                'DELETE FROM money_merchant_rules WHERE user_id = ? AND lower(merchant) = lower(?)',
                [$userId, trim((string) $txn['payee'])]
            );
        }

        return $this->money->transaction($userId, $transactionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function setEatenOn(int $userId, int $transactionId, mixed $eatenOn): array
    {
        $this->money->transaction($userId, $transactionId);
        $date = $eatenOn === null || $eatenOn === '' ? null : $this->money->cleanDate($eatenOn);
        $this->db->execute(
            'UPDATE money_transactions SET eaten_on = ? WHERE id = ? AND user_id = ?',
            [$date, $transactionId, $userId]
        );

        return $this->money->transaction($userId, $transactionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function foodWeekTotals(int $userId, ?string $weekStart): array
    {
        $anchor = $weekStart !== null && $weekStart !== '' ? $this->money->cleanDate($weekStart) : $this->clock->today();
        [$start, $end] = FoodWeek::weekContaining($anchor);
        $previousStart = (new \DateTimeImmutable($start))->modify('-7 days')->format('Y-m-d');
        $rows = $this->db->fetchAll(
            'SELECT COALESCE(t.eaten_on, t.posted_on) AS spent_on,
                    COALESCE(o.category, t.category) AS category,
                    t.amount
             FROM money_transactions t
             LEFT JOIN money_category_overrides o ON o.transaction_id = t.id AND o.user_id = t.user_id
             WHERE t.user_id = ? AND t.amount < 0
               AND COALESCE(t.eaten_on, t.posted_on) >= ? AND COALESCE(t.eaten_on, t.posted_on) <= ?',
            [$userId, $previousStart, $end]
        );
        $current = ['groceries' => 0.0, 'eating_out' => 0.0];
        $previous = ['groceries' => 0.0, 'eating_out' => 0.0];
        foreach ($rows as $row) {
            $bucket = FoodWeek::bucket((string) $row['category']);
            if ($bucket === null) {
                continue;
            }
            $day = substr((string) $row['spent_on'], 0, 10);
            $spent = -1 * (float) $row['amount'];
            if ($day >= $start && $day <= $end) {
                $current[$bucket] += $spent;
            } elseif ($day >= $previousStart && $day < $start) {
                $previous[$bucket] += $spent;
            }
        }
        $options = $this->options($userId);

        return FoodWeek::report(
            $start,
            $end,
            $previousStart,
            $current,
            $previous,
            $options['groceries_weekly'],
            $options['eating_out_weekly'],
        );
    }

    /**
     * @return array{stale_hours: int, groceries_weekly: float, eating_out_weekly: float}
     */
    public function options(int $userId): array
    {
        return MoneyOptions::read($this->db, $userId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array{stale_hours: int, groceries_weekly: float, eating_out_weekly: float}
     */
    public function saveOptions(int $userId, array $input): array
    {
        return MoneyOptions::save($this->db, $userId, $input);
    }

    /**
     * One read for net worth, cash, debts, utilization, payments, inflows,
     * benefit pots, and the food week. Net worth is the account total.
     * Debt balances are listed beside it.
     *
     * @return array<string, mixed>
     */
    public function summary(int $userId): array
    {
        $accounts = $this->money->accounts($userId);
        $net = 0.0;
        $cash = 0.0;
        $stale = false;
        foreach ($accounts as $account) {
            $balance = (float) $account['balance'];
            $net += $account['kind'] === 'credit' ? -$balance : $balance;
            if (in_array($account['kind'], ['checking', 'savings', 'cash'], true)) {
                $cash += $balance;
            }
            if (!empty($account['stale'])) {
                $stale = true;
            }
        }
        $debts = $this->plan->debts($userId);
        $debtTotal = 0.0;
        foreach ($debts as $debt) {
            $debtTotal += (float) $debt['balance'];
            if (!empty($debt['stale'])) {
                $stale = true;
            }
        }
        $pots = $this->benefitPots($userId);
        foreach ($pots as $pot) {
            if (!empty($pot['stale'])) {
                $stale = true;
            }
        }
        $options = $this->options($userId);
        $syncErrors = $this->plaid !== null ? $this->plaid->syncErrors($userId) : [];
        if ($syncErrors !== []) {
            $stale = true;
        }
        $upcoming = array_values(array_filter(
            $this->payments($userId),
            static fn (array $row): bool => in_array($row['status'], ['scheduled', 'pending'], true)
        ));

        return [
            'as_of'              => $this->clock->today(),
            'net_worth'          => round($net, 2),
            'cash'               => round($cash, 2),
            'debts'              => ['total' => round($debtTotal, 2), 'items' => $debts],
            'utilization'        => $this->plan->creditUtilization($userId),
            'upcoming_payments'  => $upcoming,
            'expected_inflows'   => $this->inflows($userId, 'expected'),
            'benefit_pots'       => $pots,
            'food_week'          => $this->foodWeekTotals($userId, null),
            'accounts'           => $accounts,
            'freshness'          => [
                'threshold_hours' => $options['stale_hours'],
                'stale'           => $stale,
                'sync_errors'     => $syncErrors,
            ],
        ];
    }

    /**
     * @return array<string, ?float>
     */
    private function componentRow(int $userId, int $debtId): array
    {
        $row = $this->db->fetchOne(
            'SELECT principal, unapplied_funds, late_charges, fees, interest
             FROM money_debt_components WHERE debt_id = ? AND user_id = ?',
            [$debtId, $userId]
        );
        $out = [];
        foreach (DebtComponents::KINDS as $kind) {
            $out[$kind] = $this->optionalFloat($row[$kind] ?? null);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(int $userId, int $id): array
    {
        return $this->formatPayment($this->paymentRow($userId, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_payments WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Payment not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPayment(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'bill_id'        => $this->storedId($row['bill_id']),
            'debt_id'        => $this->storedId($row['debt_id']),
            'amount'         => (float) $row['amount'],
            'status'         => (string) $row['status'],
            'due_on'         => $this->dateOrNull($row['due_on']),
            'note'           => $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
            'transaction_id' => $this->storedId($row['transaction_id']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inflow(int $userId, int $id): array
    {
        return $this->formatInflow($this->inflowRow($userId, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function inflowRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_expected_inflows WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Inflow not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatInflow(array $row): array
    {
        return [
            'id'             => (int) $row['id'],
            'kind'           => (string) $row['kind'],
            'source'         => (string) $row['source'],
            'amount'         => (float) $row['amount'],
            'expected_on'    => substr((string) $row['expected_on'], 0, 10),
            'status'         => (string) $row['status'],
            'transaction_id' => $this->storedId($row['transaction_id']),
            'received_on'    => $this->dateOrNull($row['received_on']),
            'note'           => $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pot(int $userId, int $id): array
    {
        return $this->formatPot($this->potRow($userId, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function potRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_benefit_pots WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Benefit pot not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPot(array $row): array
    {
        $fresh = MoneyFreshness::describe($row['last_synced_at'] ?? null, $this->options((int) $row['user_id'])['stale_hours'], $this->clock->now());

        return [
            'id'                => (int) $row['id'],
            'kind'              => (string) $row['kind'],
            'name'              => (string) $row['name'],
            'balance'           => (float) $row['balance'],
            'plan_year'         => (int) $row['plan_year'],
            'deadline'          => $this->dateOrNull($row['deadline']),
            'use_it_or_lose_it' => $this->optionalFloat($row['use_it_or_lose_it']),
            'last_synced_at'    => $fresh['last_synced_at'],
            'stale'             => $fresh['stale'],
            'archived'          => $row['archived_at'] !== null,
        ];
    }

    private function requireTarget(int $userId, ?int $billId, ?int $debtId): void
    {
        if ($billId === null && $debtId === null) {
            throw ValidationException::withFields(['bill_id' => 'A payment needs a bill or a debt.']);
        }
        if ($billId !== null) {
            $bill = $this->db->fetchOne('SELECT id FROM money_bills WHERE id = ? AND user_id = ?', [$billId, $userId]);
            if ($bill === null) {
                throw new NotFoundException('Bill not found.');
            }
        }
        if ($debtId !== null) {
            $this->plan->debt($userId, $debtId);
        }
    }

    private function optionalTransaction(int $userId, mixed $value): ?int
    {
        $id = $this->optionalId($value, 'transaction_id');
        if ($id === null) {
            return null;
        }
        $this->money->transaction($userId, $id);

        return $id;
    }

    private function transactionSnapshot(int $userId, int $id): string
    {
        $row = $this->db->fetchOne(
            'SELECT posted_on, amount, payee, category, note, eaten_on FROM money_transactions WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );

        return json_encode($row, JSON_THROW_ON_ERROR);
    }

    private function optionalId(mixed $value, string $field): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 1) {
            throw ValidationException::withFields([$field => 'Use an id.']);
        }

        return (int) $value;
    }

    private function storedId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function nonNegative(mixed $value, string $field): float
    {
        $amount = $this->money->cleanAmount($value, $field);
        if ($amount < 0) {
            throw ValidationException::withFields([$field => 'Cannot be negative.']);
        }

        return $amount;
    }

    private function optionalNonNegative(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->nonNegative($value, $field);
    }

    private function optionalFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(mixed $value, array $allowed, string $field): string
    {
        $choice = strtolower(trim((string) $value));
        if (!in_array($choice, $allowed, true)) {
            throw ValidationException::withFields([$field => 'Use one of: ' . implode(', ', $allowed) . '.']);
        }

        return $choice;
    }

    private function optionalDate(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return $this->money->cleanDate($value);
        } catch (ValidationException $e) {
            $fields = $e->fields();
            throw ValidationException::withFields([$field => $fields['date'] ?? 'Date must be YYYY-MM-DD.']);
        }
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return substr((string) $value, 0, 10);
    }

    private function note(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 2000) {
            throw ValidationException::withFields(['note' => 'Note must be 2000 characters or fewer.']);
        }
        AccountNumberGuard::assertNoFullNumber($text, 'note');

        return $text;
    }

    private function label(mixed $value, string $field, int $max): string
    {
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => $field . ' must be 1–' . $max . ' characters.']);
        }
        AccountNumberGuard::assertNoFullNumber($text, $field);

        return $text;
    }

    private function planYear(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields(['plan_year' => 'Plan year must be a year.']);
        }
        $year = (int) $value;
        if ($year < 2000 || $year > 2100) {
            throw ValidationException::withFields(['plan_year' => 'Plan year must be between 2000 and 2100.']);
        }

        return $year;
    }

    private function truth(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
