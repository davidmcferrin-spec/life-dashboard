<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Monthly budget, snowball, sinking funds, Baby Step, credit, and retirement.
 * The 15% retirement target is a display rule, not a stored secret.
 */
final class MoneyPlanService
{
    public const TARGET_RATE = 0.15;

    public const FOOD_CAP = 250.0;

    /** @var array<int, string> */
    public const STEPS = [
        1 => 'Starter emergency fund',
        2 => 'Debt snowball',
        3 => 'Full emergency fund of 3–6 months of expenses',
        4 => 'Retirement at 15% of income',
        5 => 'College for kids',
        6 => 'Pay off the house',
        7 => 'Build wealth and give',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly MoneyService $money,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function budget(int $userId, int $year, int $month): array
    {
        $this->checkMonth($year, $month);
        $from = sprintf('%04d-%02d-01', $year, $month);
        $to = (new \DateTimeImmutable($from))->modify('last day of this month')->format('Y-m-d');
        $lines = $this->db->fetchAll(
            'SELECT b.*, c.name AS category_name
             FROM money_budget_lines b
             JOIN money_categories c ON c.id = b.category_id
             WHERE b.user_id = ? AND b.year = ? AND b.month = ?
             ORDER BY c.name ASC',
            [$userId, $year, $month]
        );
        $spentRows = $this->db->fetchAll(
            'SELECT category_id, SUM(-amount) AS spent
             FROM money_transactions
             WHERE user_id = ? AND posted_on >= ? AND posted_on <= ? AND amount < 0 AND category_id IS NOT NULL
             GROUP BY category_id',
            [$userId, $from, $to]
        );
        $spent = [];
        foreach ($spentRows as $row) {
            $spent[(int) $row['category_id']] = (float) $row['spent'];
        }
        $out = [];
        foreach ($lines as $line) {
            $id = (int) $line['category_id'];
            $actual = $spent[$id] ?? 0.0;
            $planned = (float) $line['amount'];
            $out[] = [
                'category'  => (string) $line['category_name'],
                'planned'   => $planned,
                'actual'    => round($actual, 2),
                'remaining' => round($planned - $actual, 2),
                'due_day'   => $line['due_day'] !== null ? (int) $line['due_day'] : null,
            ];
        }
        return ['year' => $year, 'month' => $month, 'lines' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    public function setBudgetLine(int $userId, int $year, int $month, string $category, float $amount, ?int $dueDay): array
    {
        $this->checkMonth($year, $month);
        if ($amount < 0) {
            throw ValidationException::withFields(['amount' => 'A budget line is a positive planned amount.']);
        }
        $amount = round($amount, 2);
        $due = $this->dueDay($dueDay);
        $categoryId = $this->money->ensureCategory($userId, $category, -1, 'expense');
        $this->db->execute(
            'INSERT INTO money_budget_lines (user_id, year, month, category_id, amount, due_day)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT (user_id, year, month, category_id) DO UPDATE
                SET amount = EXCLUDED.amount, due_day = EXCLUDED.due_day',
            [$userId, $year, $month, $categoryId, $amount, $due]
        );
        return $this->budget($userId, $year, $month);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function debts(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM money_debts WHERE user_id = ? AND archived_at IS NULL ORDER BY payoff_order ASC, name ASC',
            [$userId]
        );
        return array_map(fn (array $row): array => $this->formatDebt($row), $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveDebt(int $userId, array $input, ?int $id = null): array
    {
        $existing = $id !== null ? $this->debtRow($userId, $id) : null;
        $fallback = $existing ?? [];
        $name = trim((string) ($input['name'] ?? $fallback['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withFields(['name' => 'Debt name must be 1–80 characters.']);
        }
        AccountNumberGuard::assertNoFullNumber($name, 'name');
        $balance = round($this->money->cleanAmount($input['balance'] ?? $fallback['balance'] ?? 0, 'balance'), 2);
        if ($balance < 0) {
            throw ValidationException::withFields(['balance' => 'A debt balance is the amount still owed.']);
        }
        $apr = round($this->money->cleanAmount($input['apr'] ?? $input['apr_percent'] ?? $fallback['apr_percent'] ?? 0, 'apr'), 4);
        $minimum = round($this->money->cleanAmount($input['minimum'] ?? $input['minimum_payment'] ?? $fallback['minimum_payment'] ?? 0, 'minimum'), 2);
        if ($minimum < 0) {
            throw ValidationException::withFields(['minimum' => 'Minimum payment cannot be negative.']);
        }
        $order = (int) ($input['payoff_order'] ?? $fallback['payoff_order'] ?? 1);
        if ($order < 1 || $order > 999) {
            throw ValidationException::withFields(['payoff_order' => 'Payoff order starts at 1.']);
        }
        $targetRaw = $input['target_payoff_on'] ?? $input['target'] ?? ($fallback['target_payoff_on'] ?? null);
        $target = $targetRaw !== null && $targetRaw !== '' ? $this->money->cleanDate($targetRaw) : null;
        if (array_key_exists('due_day', $input)) {
            $due = $input['due_day'] === null || $input['due_day'] === '' ? null : $this->dueDay((int) $input['due_day']);
        } else {
            $due = $existing !== null && $existing['due_day'] !== null ? (int) $existing['due_day'] : null;
        }
        if (array_key_exists('last4', $input)) {
            $last4 = AccountNumberGuard::last4($input['last4']);
        } else {
            $last4 = $existing !== null ? $this->trimLast4($existing['last4'] ?? null) : null;
        }
        $accountId = $fallback['account_id'] ?? null;
        if (isset($input['account']) && trim((string) $input['account']) !== '') {
            $account = $this->money->accountByName($userId, (string) $input['account']);
            $accountId = $account['id'] ?? null;
        }

        if ($existing === null) {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO money_debts (user_id, account_id, name, last4, balance, apr_percent, minimum_payment, payoff_order, target_payoff_on, due_day)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, name) DO UPDATE SET
                    balance = EXCLUDED.balance, apr_percent = EXCLUDED.apr_percent,
                    minimum_payment = EXCLUDED.minimum_payment, payoff_order = EXCLUDED.payoff_order,
                    target_payoff_on = COALESCE(EXCLUDED.target_payoff_on, money_debts.target_payoff_on),
                    last4 = COALESCE(EXCLUDED.last4, money_debts.last4),
                    due_day = COALESCE(EXCLUDED.due_day, money_debts.due_day),
                    updated_at = (now() AT TIME ZONE \'UTC\')
                 RETURNING id',
                [$userId, $accountId, $name, $last4, $balance, $apr, $minimum, $order, $target, $due]
            );
        } else {
            $this->db->execute(
                'UPDATE money_debts SET name = ?, last4 = ?, balance = ?, apr_percent = ?, minimum_payment = ?,
                    payoff_order = ?, target_payoff_on = ?, due_day = ?, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [$name, $last4, $balance, $apr, $minimum, $order, $target, $due, $id, $userId]
            );
        }
        return $this->formatDebt($this->debtRow($userId, (int) $id));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateDebt(int $userId, int $id, array $input): array
    {
        return $this->saveDebt($userId, $input, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sinkingFunds(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM money_sinking_funds WHERE user_id = ? AND archived_at IS NULL ORDER BY due_on NULLS LAST, name ASC',
            [$userId]
        );
        return array_map(fn (array $row): array => $this->formatFund($row), $rows);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveSinkingFund(int $userId, array $input, ?int $id = null): array
    {
        $existing = $id !== null ? $this->fundRow($userId, $id) : null;
        $fallback = $existing ?? [];
        $name = trim((string) ($input['name'] ?? $fallback['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withFields(['name' => 'Fund name must be 1–80 characters.']);
        }
        AccountNumberGuard::assertNoFullNumber($name, 'name');
        $target = round($this->money->cleanAmount($input['target'] ?? $input['target_amount'] ?? $fallback['target_amount'] ?? 0, 'target'), 2);
        $balance = round($this->money->cleanAmount($input['balance'] ?? $fallback['balance'] ?? 0, 'balance'), 2);
        if ($target < 0 || $balance < 0) {
            throw ValidationException::withFields(['balance' => 'Amounts cannot be negative.']);
        }
        $due = $input['due_on'] ?? $input['due'] ?? $fallback['due_on'] ?? null;
        $due = $due !== null && $due !== '' ? $this->money->cleanDate($due) : null;
        if ($existing === null) {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO money_sinking_funds (user_id, name, target_amount, balance, due_on)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, name) DO UPDATE SET
                    target_amount = EXCLUDED.target_amount, balance = EXCLUDED.balance,
                    due_on = COALESCE(EXCLUDED.due_on, money_sinking_funds.due_on),
                    updated_at = (now() AT TIME ZONE \'UTC\')
                 RETURNING id',
                [$userId, $name, $target, $balance, $due]
            );
        } else {
            $this->db->execute(
                'UPDATE money_sinking_funds SET name = ?, target_amount = ?, balance = ?, due_on = ?, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [$name, $target, $balance, $due, $id, $userId]
            );
        }
        return $this->formatFund($this->fundRow($userId, (int) $id));
    }

    /**
     * @param array<string, mixed> $input
     */
    public function updateSinkingFund(int $userId, int $id, array $input): array
    {
        return $this->saveSinkingFund($userId, $input, $id);
    }

    /**
     * @return array<string, mixed>
     */
    public function babyStep(int $userId): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_baby_steps WHERE user_id = ?', [$userId]);
        if ($row === null) {
            return ['step' => null, 'name' => null, 'note' => null];
        }
        $step = (int) $row['step'];
        return [
            'step' => $step,
            'name' => self::STEPS[$step] ?? null,
            'note' => $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setBabyStep(int $userId, int $step, ?string $note): array
    {
        if ($step < 1 || $step > 7) {
            throw ValidationException::withFields(['step' => 'Baby Step is 1 through 7.']);
        }
        $note = $note !== null ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }
        if ($note !== null) {
            AccountNumberGuard::assertNoFullNumber($note, 'note');
            $note = mb_substr($note, 0, 500);
        }
        $this->db->execute(
            'INSERT INTO money_baby_steps (user_id, step, note) VALUES (?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET step = EXCLUDED.step, note = EXCLUDED.note, updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $step, $note]
        );
        return $this->babyStep($userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function addCreditScore(int $userId, string $date, int $score, ?float $utilization): array
    {
        $date = $this->money->cleanDate($date);
        if ($score < 300 || $score > 850) {
            throw ValidationException::withFields(['score' => 'Credit score must be 300–850.']);
        }
        if ($utilization !== null) {
            $utilization = round($utilization, 2);
        }
        $this->db->execute(
            'INSERT INTO money_credit_scores (user_id, as_of, score, utilization_percent) VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id, as_of) DO UPDATE SET score = EXCLUDED.score, utilization_percent = EXCLUDED.utilization_percent',
            [$userId, $date, $score, $utilization]
        );
        return $this->credit($userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function credit(int $userId): array
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM money_credit_scores WHERE user_id = ? ORDER BY as_of DESC LIMIT 1',
            [$userId]
        );
        $computed = $this->computedUtilization($userId);
        $stored = $row !== null && $row['utilization_percent'] !== null ? (float) $row['utilization_percent'] : null;
        return [
            'as_of'                => $row !== null ? (string) $row['as_of'] : null,
            'score'                => $row !== null ? (int) $row['score'] : null,
            'utilization_percent'  => $computed ?? $stored,
            'utilization_source'   => $computed !== null ? 'limits' : ($stored !== null ? 'stored' : null),
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveRetirement(int $userId, array $input): array
    {
        $current = $this->db->fetchOne('SELECT * FROM money_retirement WHERE user_id = ?', [$userId]);
        $rate = array_key_exists('contribution_rate', $input) ? $this->rate($input['contribution_rate']) : ($current['contribution_rate'] ?? null);
        $income = array_key_exists('annual_income', $input) ? $this->optionalMoney($input['annual_income'], 'annual_income') : ($current['annual_income'] ?? null);
        $match = array_key_exists('employer_match_rate', $input) ? $this->rate($input['employer_match_rate']) : ($current['employer_match_rate'] ?? null);
        $this->db->execute(
            'INSERT INTO money_retirement (user_id, contribution_rate, annual_income, employer_match_rate)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (user_id) DO UPDATE SET
                contribution_rate = EXCLUDED.contribution_rate,
                annual_income = EXCLUDED.annual_income,
                employer_match_rate = EXCLUDED.employer_match_rate,
                updated_at = (now() AT TIME ZONE \'UTC\')',
            [$userId, $rate, $income, $match]
        );
        return $this->retirement($userId);
    }

    /**
     * @return array<string, mixed>
     */
    public function retirement(int $userId): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_retirement WHERE user_id = ?', [$userId]);
        $rate = $row !== null && $row['contribution_rate'] !== null ? (float) $row['contribution_rate'] : null;
        return [
            'contribution_rate'   => $rate,
            'target'              => self::TARGET_RATE,
            'annual_income'       => $row !== null && $row['annual_income'] !== null ? (float) $row['annual_income'] : null,
            'employer_match_rate' => $row !== null && $row['employer_match_rate'] !== null ? (float) $row['employer_match_rate'] : null,
            'on_track'            => $rate !== null && $rate + 0.0001 >= self::TARGET_RATE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function foodWeek(int $userId): array
    {
        [$from, $to] = $this->week($this->clock->today());
        $spent = (float) $this->db->fetchScalar(
            'SELECT COALESCE(SUM(-amount), 0) FROM money_transactions
             WHERE user_id = ? AND posted_on >= ? AND posted_on <= ? AND amount < 0 AND lower(category) = \'food\'',
            [$userId, $from, $to]
        );
        $capRow = $this->db->fetchScalar(
            'SELECT weekly_cap FROM money_categories WHERE user_id = ? AND lower(name) = \'food\'',
            [$userId]
        );
        $cap = $capRow !== null ? (float) $capRow : self::FOOD_CAP;
        $spent = round($spent, 2);
        return [
            'spent' => $spent,
            'cap'   => $cap,
            'over'  => $spent > $cap,
            'from'  => $from,
            'to'    => $to,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function billsDue(int $userId): array
    {
        $today = new \DateTimeImmutable($this->clock->today());
        $days = [];
        for ($i = 0; $i < 10; $i++) {
            $day = $today->modify('+' . $i . ' days');
            $days[(int) $day->format('j')][] = $day;
        }
        $out = [];
        $year = (int) $today->format('Y');
        $month = (int) $today->format('n');
        $next = $today->modify('first day of next month');
        $lines = $this->db->fetchAll(
            'SELECT b.amount, b.due_day, b.year, b.month, c.name
             FROM money_budget_lines b
             JOIN money_categories c ON c.id = b.category_id
             WHERE b.user_id = ? AND b.due_day IS NOT NULL
               AND ((b.year = ? AND b.month = ?) OR (b.year = ? AND b.month = ?))',
            [$userId, $year, $month, (int) $next->format('Y'), (int) $next->format('n')]
        );
        foreach ($lines as $line) {
            $due = (int) $line['due_day'];
            foreach ($days[$due] ?? [] as $date) {
                if ((int) $date->format('Y') === (int) $line['year'] && (int) $date->format('n') === (int) $line['month']) {
                    $out[] = [
                        'name'   => (string) $line['name'],
                        'amount' => (float) $line['amount'],
                        'due_on' => $date->format('Y-m-d'),
                        'kind'   => 'budget',
                    ];
                }
            }
        }
        foreach ($this->debts($userId) as $debt) {
            if ($debt['due_day'] === null) {
                continue;
            }
            foreach ($days[(int) $debt['due_day']] ?? [] as $date) {
                $out[] = [
                    'name'   => (string) $debt['name'],
                    'amount' => (float) $debt['minimum_payment'],
                    'due_on' => $date->format('Y-m-d'),
                    'kind'   => 'debt',
                ];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['due_on'], $a['name']] <=> [$b['due_on'], $b['name']]);
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(int $userId): array
    {
        $today = $this->clock->today();
        $summary = $this->money->summary($userId, Clock::shiftDate($today, 364), $today);
        $when = $this->clock->today();
        $year = (int) substr($when, 0, 4);
        $month = (int) substr($when, 5, 2);
        return [
            'net_worth'      => [
                'value'     => $summary['net_worth'],
                'snapshots' => $summary['snapshots'],
            ],
            'baby_step'      => $this->babyStep($userId),
            'debts'          => $this->debts($userId),
            'food'           => $this->foodWeek($userId),
            'budget'         => $this->budget($userId, $year, $month),
            'sinking_funds'  => $this->sinkingFunds($userId),
            'bills_due'      => $this->billsDue($userId),
            'retirement'     => $this->retirement($userId),
            'credit'         => $this->credit($userId),
        ];
    }

    public static function estimatePayoff(float $balance, float $aprPercent, float $minimum, string $fromDate): ?string
    {
        if ($balance <= 0.009) {
            return $fromDate;
        }
        if ($minimum <= 0) {
            return null;
        }
        $monthly = $aprPercent / 100 / 12;
        if ($monthly <= 0) {
            $months = (int) ceil($balance / $minimum);
        } else {
            $interest = $balance * $monthly;
            if ($minimum <= $interest + 0.009) {
                return null;
            }
            $months = (int) ceil(log($minimum / ($minimum - $balance * $monthly)) / log(1 + $monthly));
        }
        if ($months > 1200) {
            return null;
        }
        return (new \DateTimeImmutable($fromDate))->modify('+' . $months . ' months')->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatDebt(array $row): array
    {
        $balance = (float) $row['balance'];
        $apr = (float) $row['apr_percent'];
        $minimum = (float) $row['minimum_payment'];
        $last4 = $this->trimLast4($row['last4'] ?? null);
        return [
            'id'                  => (int) $row['id'],
            'name'                => (string) $row['name'],
            'last4'               => $last4,
            'balance'             => $balance,
            'apr_percent'         => $apr,
            'minimum_payment'     => $minimum,
            'payoff_order'        => (int) $row['payoff_order'],
            'target_payoff_on'    => $row['target_payoff_on'] !== null ? (string) $row['target_payoff_on'] : null,
            'estimated_payoff_on' => self::estimatePayoff($balance, $apr, $minimum, $this->clock->today()),
            'due_day'             => $row['due_day'] !== null ? (int) $row['due_day'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatFund(array $row): array
    {
        $target = (float) $row['target_amount'];
        $balance = (float) $row['balance'];
        $percent = $target > 0 ? round(min(100, ($balance / $target) * 100), 1) : ($balance > 0 ? 100.0 : 0.0);
        return [
            'id'            => (int) $row['id'],
            'name'          => (string) $row['name'],
            'target_amount' => $target,
            'balance'       => $balance,
            'due_on'        => $row['due_on'] !== null ? (string) $row['due_on'] : null,
            'percent'       => $percent,
        ];
    }

    /** @return array<string, mixed> */
    private function debtRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_debts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Debt not found.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function fundRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_sinking_funds WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Sinking fund not found.');
        }
        return $row;
    }

    private function computedUtilization(int $userId): ?float
    {
        $rows = $this->db->fetchAll(
            'SELECT balance, credit_limit FROM money_accounts
             WHERE user_id = ? AND archived_at IS NULL AND kind = \'credit\' AND credit_limit IS NOT NULL AND credit_limit > 0',
            [$userId]
        );
        if ($rows === []) {
            return null;
        }
        $owed = 0.0;
        $limit = 0.0;
        foreach ($rows as $row) {
            $owed += (float) $row['balance'];
            $limit += (float) $row['credit_limit'];
        }
        if ($limit <= 0) {
            return null;
        }
        return round(($owed / $limit) * 100, 2);
    }

    /** @return array{0: string, 1: string} */
    private function week(string $today): array
    {
        $date = new \DateTimeImmutable($today);
        $monday = $date->modify('-' . ((int) $date->format('N') - 1) . ' days');
        return [$monday->format('Y-m-d'), $monday->modify('+6 days')->format('Y-m-d')];
    }

    private function checkMonth(int $year, int $month): void
    {
        if ($year < 2000 || $year > 2100 || $month < 1 || $month > 12) {
            throw ValidationException::withFields(['month' => 'Use a real year and month.']);
        }
    }

    private function dueDay(?int $day): ?int
    {
        if ($day === null || $day === 0) {
            return null;
        }
        if ($day < 1 || $day > 31) {
            throw ValidationException::withFields(['due_day' => 'Due day must be 1–31.']);
        }
        return $day;
    }

    private function rate(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw ValidationException::withFields(['contribution_rate' => 'Enter a fraction such as 0.12 for 12%.']);
        }
        $rate = (float) $value;
        if ($rate > 1 && $rate <= 100) {
            $rate /= 100;
        }
        if ($rate < 0 || $rate > 1) {
            throw ValidationException::withFields(['contribution_rate' => 'Rate must be between 0 and 1.']);
        }
        return round($rate, 4);
    }

    private function optionalMoney(mixed $value, string $field): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $amount = $this->money->cleanAmount($value, $field);
        if ($amount < 0) {
            throw ValidationException::withFields([$field => 'Cannot be negative.']);
        }
        return $amount;
    }

    private function trimLast4(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }
}
