<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Recurring bills, envelopes, and the payday cushion.
 *
 * Marking a bill paid stores the cycle. It does not create a transaction.
 * Updating funded stores a manual amount. It does not transfer.
 * A proposal is a number to review. It does not move money.
 * Weekly food stays on the food-week cap and is not an envelope.
 */
final class BillsService
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly MoneyService $money,
    ) {}

    /**
     * @return array{bills: list<array<string, mixed>>, cycle: array<string, mixed>}
     */
    public function bills(int $userId): array
    {
        $ctx = $this->context($userId);
        $rows = $this->db->fetchAll(
            'SELECT * FROM money_bills WHERE user_id = ? AND archived_at IS NULL ORDER BY name ASC',
            [$userId]
        );
        $bills = [];
        foreach ($rows as $row) {
            $bills[] = $this->presentBill($row, $ctx);
        }
        usort($bills, static fn (array $a, array $b): int => [$a['cycle_due_on'] ?? '9999', $a['name']] <=> [$b['cycle_due_on'] ?? '9999', $b['name']]);
        return ['bills' => $bills, 'cycle' => $this->cycleMeta($ctx)];
    }

    /**
     * @return array<string, mixed>
     */
    public function bill(int $userId, int $id): array
    {
        $ctx = $this->context($userId);
        $presented = $this->presentBill($this->billRow($userId, $id), $ctx);
        $presented['cycles'] = $this->cycleHistory($userId, $id);
        return $presented;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveBill(int $userId, array $input, ?int $id = null): array
    {
        $existing = $id !== null ? $this->billRow($userId, $id) : null;
        $name = trim((string) ($input['name'] ?? $existing['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withFields(['name' => 'Bill name must be 1–80 characters.']);
        }
        AccountNumberGuard::assertNoFullNumber($name, 'name');
        $this->assertUniqueName('money_bills', $userId, $name, $id, 'A bill with that name already exists.');

        $amount = round($this->money->cleanAmount($input['amount'] ?? $existing['amount'] ?? 0, 'amount'), 2);
        if ($amount < 0) {
            throw ValidationException::withFields(['amount' => 'Amount cannot be negative.']);
        }
        $cadence = $this->choice(
            $input['cadence'] ?? $existing['cadence'] ?? 'monthly',
            BillSchedule::CADENCES,
            'cadence',
            'Cadence must be monthly, biweekly, or yearly.',
        );
        $dueDay = $this->optionalDay($input, 'due_day', $existing);
        $dueMonth = $this->optionalMonth($input, $existing);
        $anchor = $this->optionalDate($input, 'anchor_on', $existing);
        $this->assertSchedule($cadence, $dueDay, $dueMonth, $anchor);

        $accountId = $this->ownedId($userId, $input, 'pay_from_account_id', 'money_accounts', 'account', $existing);
        $label = $this->label($input, 'pay_from_label', $existing);
        $debtId = $this->ownedId($userId, $input, 'debt_id', 'money_debts', 'debt', $existing);
        $categoryId = $this->ownedId($userId, $input, 'category_id', 'money_categories', 'category', $existing);
        $statementMin = $this->optionalMoney($input, 'statement_min', $existing);
        $estimated = $this->flag($input, 'estimated', $existing);
        $autopay = $this->flag($input, 'autopay', $existing);
        $defaultNotice = $this->flag($input, 'default_notice', $existing);
        $cancelled = $this->flag($input, 'cancelled_charging', $existing);
        $notes = $this->notes($input, $existing);

        if ($existing === null) {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO money_bills (
                    user_id, name, amount, estimated, cadence, due_day, due_month, anchor_on,
                    pay_from_account_id, pay_from_label, autopay, debt_id, category_id,
                    statement_min, default_notice, cancelled_charging, notes
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [
                    $userId, $name, $amount, $estimated, $cadence, $dueDay, $dueMonth, $anchor,
                    $accountId, $label, $autopay, $debtId, $categoryId,
                    $statementMin, $defaultNotice, $cancelled, $notes,
                ]
            );
        } else {
            $this->db->execute(
                'UPDATE money_bills SET
                    name = ?, amount = ?, estimated = ?, cadence = ?, due_day = ?, due_month = ?, anchor_on = ?,
                    pay_from_account_id = ?, pay_from_label = ?, autopay = ?, debt_id = ?, category_id = ?,
                    statement_min = ?, default_notice = ?, cancelled_charging = ?, notes = ?,
                    updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [
                    $name, $amount, $estimated, $cadence, $dueDay, $dueMonth, $anchor,
                    $accountId, $label, $autopay, $debtId, $categoryId,
                    $statementMin, $defaultNotice, $cancelled, $notes,
                    $id, $userId,
                ]
            );
        }
        return $this->bill($userId, (int) $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateBill(int $userId, int $id, array $input): array
    {
        return $this->saveBill($userId, $input, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveBill(int $userId, int $id): array
    {
        $this->billRow($userId, $id);
        $this->db->execute(
            "UPDATE money_bills SET archived_at = (now() AT TIME ZONE 'UTC'), updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        return $this->bill($userId, $id);
    }

    /**
     * Record paid or skipped for one cycle. Does not write a transaction.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function markCycle(int $userId, int $id, array $input): array
    {
        $row = $this->billRow($userId, $id);
        $status = strtolower(trim((string) ($input['status'] ?? 'paid')));
        if (!in_array($status, ['paid', 'skipped'], true)) {
            throw ValidationException::withFields(['status' => 'Status must be paid or skipped.']);
        }
        $ctx = $this->context($userId);
        $schedule = $this->scheduleOf($row, $ctx);
        $dueRaw = $input['cycle_due_on'] ?? $schedule['cycle_due_on'] ?? $this->clock->today();
        $due = $this->money->cleanDate($dueRaw);
        $paidOn = null;
        if ($status === 'paid') {
            $paidOn = $this->money->cleanDate($input['paid_on'] ?? $this->clock->today());
        }
        $amount = array_key_exists('amount', $input) && $input['amount'] !== null && $input['amount'] !== ''
            ? $this->positiveMoney($input['amount'], 'amount')
            : $this->pullAmount($row);
        $note = null;
        if (array_key_exists('note', $input)) {
            $note = $this->noteText($input['note']);
        }
        $this->db->execute(
            'INSERT INTO money_bill_cycles (user_id, bill_id, cycle_due_on, status, paid_on, amount, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (bill_id, cycle_due_on) DO UPDATE SET
                status = EXCLUDED.status,
                paid_on = EXCLUDED.paid_on,
                amount = EXCLUDED.amount,
                note = EXCLUDED.note',
            [$userId, $id, $due, $status, $paidOn, $amount, $note]
        );
        return $this->bill($userId, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function envelopes(int $userId): array
    {
        $ctx = $this->context($userId);
        $rows = $this->db->fetchAll(
            'SELECT * FROM money_envelopes WHERE user_id = ? AND archived_at IS NULL ORDER BY name ASC',
            [$userId]
        );
        return array_map(fn (array $row): array => $this->presentEnvelope($row, $ctx), $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function envelope(int $userId, int $id): array
    {
        return $this->presentEnvelope($this->envelopeRow($userId, $id), $this->context($userId));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveEnvelope(int $userId, array $input, ?int $id = null): array
    {
        $existing = $id !== null ? $this->envelopeRow($userId, $id) : null;
        $name = trim((string) ($input['name'] ?? $existing['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withFields(['name' => 'Envelope name must be 1–80 characters.']);
        }
        AccountNumberGuard::assertNoFullNumber($name, 'name');
        $this->assertUniqueName('money_envelopes', $userId, $name, $id, 'An envelope with that name already exists.');
        $job = $this->choice(
            $input['job_type'] ?? $existing['job_type'] ?? 'sinking',
            BillSchedule::JOB_TYPES,
            'job_type',
            'Job type must be sinking, buffer, gifts, trip, escrow, deep_clean, or other.',
        );
        $target = $this->positiveMoney($input['target_amount'] ?? $input['target'] ?? $existing['target_amount'] ?? 0, 'target_amount');
        $funded = $this->positiveMoney($input['funded_amount'] ?? $input['funded'] ?? $existing['funded_amount'] ?? 0, 'funded_amount');
        $targetOn = $this->optionalDate($input, 'target_on', $existing);
        $accountId = $this->ownedId($userId, $input, 'account_id', 'money_accounts', 'account', $existing);
        $this->assertEnvelopeAccountFree($userId, $accountId, $id);
        $notes = $this->notes($input, $existing);

        if ($existing === null) {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO money_envelopes (user_id, name, job_type, target_amount, funded_amount, target_on, account_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$userId, $name, $job, $target, $funded, $targetOn, $accountId, $notes]
            );
        } else {
            $this->db->execute(
                'UPDATE money_envelopes SET
                    name = ?, job_type = ?, target_amount = ?, funded_amount = ?, target_on = ?, account_id = ?, notes = ?,
                    updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [$name, $job, $target, $funded, $targetOn, $accountId, $notes, $id, $userId]
            );
        }
        return $this->envelope($userId, (int) $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateEnvelope(int $userId, int $id, array $input): array
    {
        return $this->saveEnvelope($userId, $input, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveEnvelope(int $userId, int $id): array
    {
        $this->envelopeRow($userId, $id);
        $this->db->execute(
            "UPDATE money_envelopes SET archived_at = (now() AT TIME ZONE 'UTC'), updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
        return $this->envelope($userId, $id);
    }

    /**
     * Store the manual funded amount. A linked account balance still wins on read.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateFunded(int $userId, int $id, array $input): array
    {
        $this->envelopeRow($userId, $id);
        if (!array_key_exists('funded_amount', $input) && !array_key_exists('funded', $input)) {
            throw ValidationException::withFields(['funded_amount' => 'Enter the funded amount.']);
        }
        $funded = $this->positiveMoney($input['funded_amount'] ?? $input['funded'] ?? null, 'funded_amount');
        $this->db->execute(
            'UPDATE money_envelopes SET funded_amount = ?, updated_at = (now() AT TIME ZONE \'UTC\') WHERE id = ? AND user_id = ?',
            [$funded, $id, $userId]
        );
        $envelope = $this->envelope($userId, $id);
        $envelope['funded_note'] = $envelope['funded_source'] === 'account'
            ? 'Manual funded amount saved. The linked account balance is what the page shows. No money was moved.'
            : 'Manual funded amount saved. No money was moved.';
        return $envelope;
    }

    /**
     * @return array<string, mixed>
     */
    public function payday(int $userId): array
    {
        $ctx = $this->context($userId);
        return $this->paydayPayload($ctx);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function savePayday(int $userId, array $input): array
    {
        $current = $this->db->fetchOne('SELECT * FROM money_payday WHERE user_id = ?', [$userId]) ?? [];
        $cadence = $this->choice(
            $input['cadence'] ?? $current['cadence'] ?? 'biweekly',
            BillSchedule::PAYDAY_CADENCES,
            'cadence',
            'Payday cadence must be weekly, biweekly, or monthly.',
        );
        $anchor = $this->optionalDate($input, 'anchor_on', $current === [] ? null : $current);
        $next = $this->optionalDate($input, 'next_on', $current === [] ? null : $current);
        $paycheck = $this->optionalMoney($input, 'paycheck_amount', $current === [] ? null : $current);
        $float = $this->optionalMoney($input, 'living_float', $current === [] ? null : $current);
        $this->db->execute(
            'INSERT INTO money_payday (user_id, cadence, anchor_on, next_on, paycheck_amount, living_float)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                cadence = EXCLUDED.cadence,
                anchor_on = EXCLUDED.anchor_on,
                next_on = EXCLUDED.next_on,
                paycheck_amount = EXCLUDED.paycheck_amount,
                living_float = EXCLUDED.living_float,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $cadence, $anchor, $next, $paycheck, $float]
        );
        return $this->payday($userId);
    }

    /**
     * Bills that pull between today and the next payday, plus the leftover.
     * Food week is reported beside this and is not subtracted.
     *
     * @return array<string, mixed>
     */
    public function pressure(int $userId): array
    {
        $ctx = $this->context($userId);
        $rows = $this->db->fetchAll(
            'SELECT * FROM money_bills WHERE user_id = ? AND archived_at IS NULL ORDER BY name ASC',
            [$userId]
        );
        $lines = [];
        foreach ($rows as $row) {
            $schedule = $this->scheduleOf($row, $ctx);
            $common = $this->pressureCommon($row, $ctx, $schedule);
            if ($schedule['open_past_on'] !== null) {
                $flags = $common['flags'];
                $flags[] = 'past_due';
                if ($schedule['past_due_count'] > 1) {
                    $flags[] = 'catch_up';
                }
                $lines[] = $common + [
                    'due_on' => $schedule['open_past_on'],
                    'status' => 'past_due',
                    'flags'  => array_values(array_unique($flags)),
                ];
            }
            foreach ($schedule['upcoming'] as $date) {
                $lines[] = $common + [
                    'due_on' => $date,
                    'status' => BillSchedule::status($ctx['today'], $date, false, false),
                    'flags'  => $common['flags'],
                ];
            }
        }
        usort($lines, static fn (array $a, array $b): int => [$a['due_on'], $a['name']] <=> [$b['due_on'], $b['name']]);

        $covered = [];
        foreach ($lines as $line) {
            if ($line['debt_id'] !== null) {
                $covered[(int) $line['debt_id']] = true;
            }
        }
        $minimums = [];
        foreach ($ctx['debts'] as $debtId => $debt) {
            if ($debt['archived_at'] !== null || isset($covered[$debtId]) || $debt['due_day'] === null) {
                continue;
            }
            $dates = BillSchedule::occurrences(
                $ctx['today'],
                $ctx['pressure_to'],
                'monthly',
                (int) $debt['due_day'],
                null,
                null,
            );
            foreach ($dates as $date) {
                $minimums[] = [
                    'debt_id' => $debtId,
                    'name'    => (string) $debt['name'],
                    'amount'  => round((float) $debt['minimum_payment'], 2),
                    'due_on'  => $date,
                ];
            }
        }
        usort($minimums, static fn (array $a, array $b): int => [$a['due_on'], $a['name']] <=> [$b['due_on'], $b['name']]);

        $pulls = 0.0;
        foreach ($lines as $line) {
            $pulls += (float) $line['amount'];
        }
        $debtTotal = 0.0;
        foreach ($minimums as $line) {
            $debtTotal += (float) $line['amount'];
        }
        $pulls = round($pulls, 2);
        $debtTotal = round($debtTotal, 2);
        $float = $ctx['living_float'] ?? 0.0;
        $paycheck = $ctx['paycheck_amount'];
        $ready = $ctx['next_payday'] !== null && $paycheck !== null;
        $unassigned = $ready ? round((float) $paycheck - $pulls - $debtTotal - (float) $float, 2) : null;

        return [
            'today'             => $ctx['today'],
            'configured'        => $ctx['next_payday'] !== null,
            'moves_money'       => false,
            'cadence'           => $ctx['cadence'],
            'anchor_on'         => $ctx['anchor_on'],
            'next_on'           => $ctx['next_on'],
            'next_payday'       => $ctx['next_payday'],
            'previous_payday'   => $ctx['previous_payday'],
            'paycheck_amount'   => $paycheck,
            'living_float'      => $ctx['living_float'],
            'cycle_from'        => $ctx['cycle_from'],
            'cycle_to'          => $ctx['cycle_to'],
            'bills'             => $lines,
            'pulls_total'       => $pulls,
            'debt_minimums'     => $minimums,
            'debt_total'        => $debtTotal,
            'unassigned'        => $unassigned,
            'formula'           => 'paycheck - bills due by payday (including one past-due payment) - debt minimums that are not already a bill - living float. Weekly food is not included. Nothing is moved.',
        ] + $this->paydayPayload($ctx);
    }

    /**
     * A suggested assignment. The envelope funded amount and account balances stay put.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function propose(int $userId, array $input): array
    {
        $envelopeId = $input['envelope_id'] ?? null;
        if (!is_numeric($envelopeId) || (int) $envelopeId < 1) {
            throw ValidationException::withFields(['envelope_id' => 'Choose an envelope.']);
        }
        $envelope = $this->envelope($userId, (int) $envelopeId);
        $amount = $this->positiveMoney($input['amount'] ?? null, 'amount');
        if ($amount <= 0) {
            throw ValidationException::withFields(['amount' => 'Enter an amount to assign.']);
        }
        $pressure = $this->pressure($userId);
        $payday = null;
        if (array_key_exists('payday_on', $input) && $input['payday_on'] !== null && $input['payday_on'] !== '') {
            $payday = $this->money->cleanDate($input['payday_on']);
        } else {
            $payday = $pressure['next_payday'];
        }
        $unassigned = $pressure['unassigned'];
        return [
            'moves_money' => false,
            'proposal' => [
                'status'            => 'proposed',
                'moves_money'       => false,
                'envelope_id'       => $envelope['id'],
                'envelope_name'     => $envelope['name'],
                'amount'            => $amount,
                'payday_on'         => $payday,
                'funded_after'      => round((float) $envelope['funded'] + $amount, 2),
                'unassigned_after'  => $unassigned === null ? null : round((float) $unassigned - $amount, 2),
                'note'              => 'Proposal only. No transfer was made. Confirm with David before moving money.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function context(int $userId): array
    {
        $today = $this->clock->today();
        $plan = $this->db->fetchOne('SELECT * FROM money_payday WHERE user_id = ?', [$userId]) ?? [];
        $cadence = isset($plan['cadence']) ? (string) $plan['cadence'] : 'biweekly';
        $anchor = $this->dateOrNull($plan['anchor_on'] ?? null);
        $explicit = $this->dateOrNull($plan['next_on'] ?? null);
        $next = BillSchedule::paydayOn($today, $cadence, $anchor, $explicit);
        if ($next !== null) {
            $previous = BillSchedule::previousPayday($next, $cadence);
            $cycleFrom = (new \DateTimeImmutable($previous))->modify('+1 day')->format('Y-m-d');
            $cycleTo = $next;
            $pressureTo = $next;
        } else {
            $cycleFrom = substr($today, 0, 8) . '01';
            $cycleTo = (new \DateTimeImmutable($cycleFrom))->modify('last day of this month')->format('Y-m-d');
            $previous = null;
            $pressureTo = $cycleTo;
        }
        $lookback = (new \DateTimeImmutable($today))->modify('-400 days')->format('Y-m-d');
        $accounts = [];
        foreach ($this->db->fetchAll('SELECT * FROM money_accounts WHERE user_id = ?', [$userId]) as $row) {
            $accounts[(int) $row['id']] = $row;
        }
        $debts = [];
        foreach ($this->db->fetchAll(
            'SELECT id, name, minimum_payment, due_day, archived_at FROM money_debts WHERE user_id = ?',
            [$userId]
        ) as $row) {
            $debts[(int) $row['id']] = $row;
        }
        $categories = [];
        foreach ($this->db->fetchAll('SELECT id, name FROM money_categories WHERE user_id = ?', [$userId]) as $row) {
            $categories[(int) $row['id']] = (string) $row['name'];
        }
        $cycles = [];
        foreach ($this->db->fetchAll(
            'SELECT * FROM money_bill_cycles WHERE user_id = ? AND cycle_due_on >= ? ORDER BY cycle_due_on ASC',
            [$userId, $lookback]
        ) as $row) {
            $cycles[(int) $row['bill_id']][$this->dateOrNull($row['cycle_due_on'])] = $row;
        }
        return [
            'today'            => $today,
            'cadence'          => $cadence,
            'anchor_on'        => $anchor,
            'next_on'          => $explicit,
            'next_payday'      => $next,
            'previous_payday'  => $previous,
            'cycle_from'       => $cycleFrom,
            'cycle_to'         => $cycleTo,
            'pressure_to'      => $pressureTo,
            'lookback'         => $lookback,
            'paycheck_amount'  => $this->nullableFloat($plan['paycheck_amount'] ?? null),
            'living_float'     => $this->nullableFloat($plan['living_float'] ?? null),
            'accounts'         => $accounts,
            'debts'            => $debts,
            'categories'       => $categories,
            'cycles'           => $cycles,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function cycleMeta(array $ctx): array
    {
        return [
            'today'           => $ctx['today'],
            'from'            => $ctx['cycle_from'],
            'to'              => $ctx['cycle_to'],
            'next_payday'     => $ctx['next_payday'],
            'configured'      => $ctx['next_payday'] !== null,
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function paydayPayload(array $ctx): array
    {
        return [
            'cadence'          => $ctx['cadence'],
            'anchor_on'        => $ctx['anchor_on'],
            'next_on'          => $ctx['next_on'],
            'next_payday'      => $ctx['next_payday'],
            'paycheck_amount'  => $ctx['paycheck_amount'],
            'living_float'     => $ctx['living_float'],
            'configured'       => $ctx['next_payday'] !== null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function scheduleOf(array $row, array $ctx): array
    {
        $today = $ctx['today'];
        $cadence = (string) $row['cadence'];
        $dueDay = $row['due_day'] !== null && $row['due_day'] !== '' ? (int) $row['due_day'] : null;
        $dueMonth = $row['due_month'] !== null && $row['due_month'] !== '' ? (int) $row['due_month'] : null;
        $anchor = $this->dateOrNull($row['anchor_on'] ?? null);
        $settled = $ctx['cycles'][(int) $row['id']] ?? [];
        $isSettled = static function (string $date) use ($settled): bool {
            if (!isset($settled[$date])) {
                return false;
            }
            return in_array((string) $settled[$date]['status'], ['paid', 'skipped'], true);
        };
        $yesterday = (new \DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
        $pastFrom = BillSchedule::pastWindowStart($today, $cadence);
        $past = $yesterday >= $pastFrom
            ? BillSchedule::occurrences($pastFrom, $yesterday, $cadence, $dueDay, $dueMonth, $anchor)
            : [];
        $openPast = array_values(array_filter($past, static fn (string $date): bool => !$isSettled($date)));
        $horizon = (new \DateTimeImmutable($today))->modify('+400 days')->format('Y-m-d');
        $future = BillSchedule::occurrences($today, $horizon, $cadence, $dueDay, $dueMonth, $anchor);
        $nextDue = null;
        foreach ($future as $date) {
            if (!$isSettled($date)) {
                $nextDue = $date;
                break;
            }
        }
        $inWindow = BillSchedule::occurrences($ctx['cycle_from'], $ctx['cycle_to'], $cadence, $dueDay, $dueMonth, $anchor);
        $cycleDue = $openPast !== [] ? $openPast[count($openPast) - 1] : ($inWindow[0] ?? $nextDue);
        $paid = $cycleDue !== null && isset($settled[$cycleDue]) && (string) $settled[$cycleDue]['status'] === 'paid';
        $skipped = $cycleDue !== null && isset($settled[$cycleDue]) && (string) $settled[$cycleDue]['status'] === 'skipped';
        $upcoming = array_values(array_filter(
            BillSchedule::occurrences($today, $ctx['pressure_to'], $cadence, $dueDay, $dueMonth, $anchor),
            static fn (string $date): bool => !$isSettled($date),
        ));
        return [
            'next_due_on'    => $nextDue,
            'cycle_due_on'   => $cycleDue,
            'status'         => BillSchedule::status($today, $cycleDue, $paid, $skipped),
            'past_due_count' => count($openPast),
            'open_past_on'   => $openPast === [] ? null : $openPast[count($openPast) - 1],
            'upcoming'       => $upcoming,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function presentBill(array $row, array $ctx): array
    {
        $schedule = $this->scheduleOf($row, $ctx);
        $accountId = $row['pay_from_account_id'] !== null && $row['pay_from_account_id'] !== ''
            ? (int) $row['pay_from_account_id'] : null;
        $account = $accountId !== null ? ($ctx['accounts'][$accountId] ?? null) : null;
        $debtId = $row['debt_id'] !== null && $row['debt_id'] !== '' ? (int) $row['debt_id'] : null;
        $categoryId = $row['category_id'] !== null && $row['category_id'] !== '' ? (int) $row['category_id'] : null;
        $archivedAccount = $account !== null && $account['archived_at'] !== null;
        $flags = $this->riskFlags($row, $account, $schedule, $archivedAccount);
        $cycleDue = $schedule['cycle_due_on'];
        $inCycle = $cycleDue !== null && $cycleDue >= $ctx['cycle_from'] && $cycleDue <= $ctx['cycle_to'];
        $amount = round((float) $row['amount'], 2);
        $statement = $this->nullableFloat($row['statement_min'] ?? null);
        return [
            'id'                   => (int) $row['id'],
            'name'                 => (string) $row['name'],
            'amount'               => $amount,
            'pull_amount'          => $this->pullAmount($row),
            'uses_statement_min'   => $amount <= 0 && $statement !== null,
            'estimated'            => (int) $row['estimated'] === 1,
            'cadence'              => (string) $row['cadence'],
            'due_day'              => $row['due_day'] !== null && $row['due_day'] !== '' ? (int) $row['due_day'] : null,
            'due_month'            => $row['due_month'] !== null && $row['due_month'] !== '' ? (int) $row['due_month'] : null,
            'anchor_on'            => $this->dateOrNull($row['anchor_on'] ?? null),
            'pay_from_account_id'  => $accountId,
            'pay_from_label'       => $this->textOrNull($row['pay_from_label'] ?? null),
            'pay_from_name'        => $account !== null ? (string) $account['name'] : null,
            'pay_from_kind'        => $account !== null ? (string) $account['kind'] : null,
            'pay_from_archived'    => $archivedAccount,
            'autopay'              => (int) $row['autopay'] === 1,
            'debt_id'              => $debtId,
            'debt_name'            => $debtId !== null && isset($ctx['debts'][$debtId]) ? (string) $ctx['debts'][$debtId]['name'] : null,
            'payment_event'        => $debtId !== null,
            'category_id'          => $categoryId,
            'category_name'        => $categoryId !== null ? ($ctx['categories'][$categoryId] ?? null) : null,
            'statement_min'        => $statement,
            'default_notice'       => (int) $row['default_notice'] === 1,
            'cancelled_charging'   => (int) $row['cancelled_charging'] === 1,
            'notes'                => $this->textOrNull($row['notes'] ?? null),
            'archived'             => $row['archived_at'] !== null,
            'next_due_on'          => $schedule['next_due_on'],
            'cycle_due_on'         => $cycleDue,
            'status'               => $schedule['status'],
            'past_due_count'       => $schedule['past_due_count'],
            'flags'                => $flags,
            'in_cycle'             => $inCycle || $schedule['status'] === 'past_due',
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $account
     * @param array<string, mixed> $schedule
     * @return list<string>
     */
    private function riskFlags(array $row, ?array $account, array $schedule, bool $archivedAccount): array
    {
        $flags = [];
        if ($schedule['status'] === 'past_due') {
            $flags[] = 'past_due';
        }
        if ($schedule['past_due_count'] > 1) {
            $flags[] = 'catch_up';
        }
        if ($archivedAccount) {
            $flags[] = 'closed_account';
        }
        if ($this->overLimit($account)) {
            $flags[] = 'over_limit';
        }
        if ((int) $row['default_notice'] === 1) {
            $flags[] = 'default_notice';
        }
        if ((int) $row['cancelled_charging'] === 1) {
            $flags[] = 'cancelled_charging';
        }
        return $flags;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $schedule
     * @return array<string, mixed>
     */
    private function pressureCommon(array $row, array $ctx, array $schedule): array
    {
        $accountId = $row['pay_from_account_id'] !== null && $row['pay_from_account_id'] !== ''
            ? (int) $row['pay_from_account_id'] : null;
        $account = $accountId !== null ? ($ctx['accounts'][$accountId] ?? null) : null;
        $debtId = $row['debt_id'] !== null && $row['debt_id'] !== '' ? (int) $row['debt_id'] : null;
        $archivedAccount = $account !== null && $account['archived_at'] !== null;
        $flags = [];
        if ($archivedAccount) {
            $flags[] = 'closed_account';
        }
        if ($this->overLimit($account)) {
            $flags[] = 'over_limit';
        }
        if ((int) $row['default_notice'] === 1) {
            $flags[] = 'default_notice';
        }
        if ((int) $row['cancelled_charging'] === 1) {
            $flags[] = 'cancelled_charging';
        }
        return [
            'bill_id'              => (int) $row['id'],
            'name'                 => (string) $row['name'],
            'amount'               => $this->pullAmount($row),
            'estimated'            => (int) $row['estimated'] === 1,
            'autopay'              => (int) $row['autopay'] === 1,
            'pay_from_account_id'  => $accountId,
            'pay_from_name'        => $account !== null ? (string) $account['name'] : null,
            'pay_from_label'       => $this->textOrNull($row['pay_from_label'] ?? null),
            'debt_id'              => $debtId,
            'debt_name'            => $debtId !== null && isset($ctx['debts'][$debtId]) ? (string) $ctx['debts'][$debtId]['name'] : null,
            'past_due_count'       => $schedule['past_due_count'],
            'flags'                => $flags,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $ctx
     * @return array<string, mixed>
     */
    private function presentEnvelope(array $row, array $ctx): array
    {
        $manual = round((float) $row['funded_amount'], 2);
        $target = round((float) $row['target_amount'], 2);
        $accountId = $row['account_id'] !== null && $row['account_id'] !== '' ? (int) $row['account_id'] : null;
        $account = $accountId !== null ? ($ctx['accounts'][$accountId] ?? null) : null;
        $archived = $account !== null && $account['archived_at'] !== null;
        $live = $account !== null && !$archived;
        $funded = $live ? round((float) $account['balance'], 2) : $manual;
        $targetOn = $this->dateOrNull($row['target_on'] ?? null);
        $job = (string) $row['job_type'];
        return [
            'id'                => (int) $row['id'],
            'name'              => (string) $row['name'],
            'job_type'          => $job,
            'job_label'         => BillSchedule::JOB_LABELS[$job] ?? 'Other',
            'target_amount'     => $target,
            'funded_amount'     => $manual,
            'funded'            => $funded,
            'funded_source'     => $live ? 'account' : 'manual',
            'account_id'        => $accountId,
            'account_name'      => $account !== null ? (string) $account['name'] : null,
            'account_archived'  => $archived,
            'target_on'         => $targetOn,
            'percent'           => BillSchedule::progress($funded, $target),
            'on_track'          => BillSchedule::onTrack($ctx['today'], $targetOn, $funded, $target),
            'notes'             => $this->textOrNull($row['notes'] ?? null),
            'archived'          => $row['archived_at'] !== null,
        ];
    }

    /**
     * @param array<string, mixed>|null $account
     */
    private function overLimit(?array $account): bool
    {
        if ($account === null || (string) $account['kind'] !== 'credit') {
            return false;
        }
        if ($account['credit_limit'] === null || $account['credit_limit'] === '') {
            return false;
        }
        return (float) $account['balance'] > (float) $account['credit_limit'] + 0.009;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function pullAmount(array $row): float
    {
        $amount = round((float) $row['amount'], 2);
        $statement = $this->nullableFloat($row['statement_min'] ?? null);
        if ($amount <= 0 && $statement !== null) {
            return $statement;
        }
        return $amount;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function cycleHistory(int $userId, int $id): array
    {
        $rows = $this->db->fetchAll(
            'SELECT cycle_due_on, status, paid_on, amount, note
             FROM money_bill_cycles WHERE user_id = ? AND bill_id = ?
             ORDER BY cycle_due_on DESC LIMIT 24',
            [$userId, $id]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'cycle_due_on' => $this->dateOrNull($row['cycle_due_on']),
                'status'       => (string) $row['status'],
                'paid_on'      => $this->dateOrNull($row['paid_on'] ?? null),
                'amount'       => $this->nullableFloat($row['amount'] ?? null),
                'note'         => $this->textOrNull($row['note'] ?? null),
            ];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function billRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_bills WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Bill not found.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function envelopeRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_envelopes WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Envelope not found.');
        }
        return $row;
    }

    private function assertUniqueName(string $table, int $userId, string $name, ?int $id, string $message): void
    {
        if ($table === 'money_bills') {
            $row = $this->db->fetchOne(
                'SELECT id FROM money_bills WHERE user_id = ? AND name = ? AND id <> ?',
                [$userId, $name, $id ?? 0]
            );
        } elseif ($table === 'money_envelopes') {
            $row = $this->db->fetchOne(
                'SELECT id FROM money_envelopes WHERE user_id = ? AND name = ? AND id <> ?',
                [$userId, $name, $id ?? 0]
            );
        } else {
            throw new \InvalidArgumentException('Unknown name table.');
        }
        if ($row !== null) {
            throw ValidationException::withFields(['name' => $message]);
        }
    }

    private function assertEnvelopeAccountFree(int $userId, ?int $accountId, ?int $id): void
    {
        if ($accountId === null) {
            return;
        }
        $other = $this->db->fetchOne(
            'SELECT id FROM money_envelopes
             WHERE user_id = ? AND account_id = ? AND archived_at IS NULL AND id <> ?',
            [$userId, $accountId, $id ?? 0]
        );
        if ($other !== null) {
            throw ValidationException::withFields(['account_id' => 'That account is already linked to an envelope.']);
        }
    }

    private function assertSchedule(string $cadence, ?int $dueDay, ?int $dueMonth, ?string $anchor): void
    {
        if ($cadence === 'monthly' && $dueDay === null) {
            throw ValidationException::withFields(['due_day' => 'A monthly bill needs a due day.']);
        }
        if ($cadence === 'yearly' && ($dueDay === null || $dueMonth === null)) {
            throw ValidationException::withFields(['due_month' => 'A yearly bill needs a month and a due day.']);
        }
        if ($cadence === 'biweekly' && $anchor === null) {
            throw ValidationException::withFields(['anchor_on' => 'A biweekly bill needs a known due date.']);
        }
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function optionalDay(array $input, string $key, ?array $existing): ?int
    {
        if (!array_key_exists($key, $input)) {
            if ($existing === null || $existing[$key] === null || $existing[$key] === '') {
                return null;
            }
            return (int) $existing[$key];
        }
        if ($input[$key] === null || $input[$key] === '') {
            return null;
        }
        $day = (int) $input[$key];
        if ($day < 1 || $day > 31) {
            throw ValidationException::withFields([$key => 'Due day must be 1–31.']);
        }
        return $day;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function optionalMonth(array $input, ?array $existing): ?int
    {
        if (!array_key_exists('due_month', $input)) {
            if ($existing === null || $existing['due_month'] === null || $existing['due_month'] === '') {
                return null;
            }
            return (int) $existing['due_month'];
        }
        if ($input['due_month'] === null || $input['due_month'] === '') {
            return null;
        }
        $month = (int) $input['due_month'];
        if ($month < 1 || $month > 12) {
            throw ValidationException::withFields(['due_month' => 'Month must be 1–12.']);
        }
        return $month;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function optionalDate(array $input, string $key, ?array $existing): ?string
    {
        if (!array_key_exists($key, $input)) {
            return $existing !== null ? $this->dateOrNull($existing[$key] ?? null) : null;
        }
        if ($input[$key] === null || $input[$key] === '') {
            return null;
        }
        return $this->money->cleanDate($input[$key]);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function optionalMoney(array $input, string $key, ?array $existing): ?float
    {
        if (!array_key_exists($key, $input)) {
            return $existing !== null ? $this->nullableFloat($existing[$key] ?? null) : null;
        }
        if ($input[$key] === null || $input[$key] === '') {
            return null;
        }
        return $this->positiveMoney($input[$key], $key);
    }

    private function positiveMoney(mixed $value, string $field): float
    {
        $amount = round($this->money->cleanAmount($value, $field), 2);
        if ($amount < 0) {
            throw ValidationException::withFields([$field => 'Cannot be negative.']);
        }
        return $amount;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function ownedId(
        int $userId,
        array $input,
        string $key,
        string $table,
        string $field,
        ?array $existing,
    ): ?int {
        if (!array_key_exists($key, $input)) {
            if ($existing === null || $existing[$key] === null || $existing[$key] === '') {
                return null;
            }
            return (int) $existing[$key];
        }
        if ($input[$key] === null || $input[$key] === '' || $input[$key] === 0 || $input[$key] === '0') {
            return null;
        }
        if (!is_numeric($input[$key]) || (int) $input[$key] < 1) {
            throw ValidationException::withFields([$field => 'Unknown ' . $field . '.']);
        }
        $id = (int) $input[$key];
        if ($table === 'money_accounts') {
            $found = $this->db->fetchOne('SELECT id FROM money_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);
        } elseif ($table === 'money_debts') {
            $found = $this->db->fetchOne('SELECT id FROM money_debts WHERE id = ? AND user_id = ?', [$id, $userId]);
        } elseif ($table === 'money_categories') {
            $found = $this->db->fetchOne('SELECT id FROM money_categories WHERE id = ? AND user_id = ?', [$id, $userId]);
        } else {
            throw new \InvalidArgumentException('Unknown owner table.');
        }
        if ($found === null) {
            throw ValidationException::withFields([$field => 'Unknown ' . $field . '.']);
        }
        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function label(array $input, string $key, ?array $existing): ?string
    {
        if (!array_key_exists($key, $input)) {
            return $existing !== null ? $this->textOrNull($existing[$key] ?? null) : null;
        }
        if ($input[$key] === null) {
            return null;
        }
        $text = trim((string) $input[$key]);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 80) {
            throw ValidationException::withFields([$key => 'Use 80 characters or fewer.']);
        }
        AccountNumberGuard::assertNoFullNumber($text, $key);
        return $text;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function flag(array $input, string $key, ?array $existing): int
    {
        if (!array_key_exists($key, $input)) {
            return $existing !== null && (int) ($existing[$key] ?? 0) === 1 ? 1 : 0;
        }
        $value = $input[$key];
        if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
            return 1;
        }
        if ($value === false || $value === 0 || $value === '0' || $value === 'false' || $value === null || $value === '') {
            return 0;
        }
        throw ValidationException::withFields([$key => 'Use true or false.']);
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $existing
     */
    private function notes(array $input, ?array $existing): ?string
    {
        if (!array_key_exists('notes', $input)) {
            return $existing !== null ? $this->textOrNull($existing['notes'] ?? null) : null;
        }
        return $this->noteText($input['notes']);
    }

    private function noteText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > 2000) {
            throw ValidationException::withFields(['notes' => 'Notes must be 2000 characters or fewer.']);
        }
        AccountNumberGuard::assertNoFullNumber($text, 'notes');
        return $text;
    }

    /**
     * @param list<string> $allowed
     */
    private function choice(mixed $value, array $allowed, string $field, string $message): string
    {
        $text = strtolower(trim((string) $value));
        if (!in_array($text, $allowed, true)) {
            throw ValidationException::withFields([$field => $message]);
        }
        return $text;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return substr((string) $value, 0, 10);
    }

    private function textOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }
}
