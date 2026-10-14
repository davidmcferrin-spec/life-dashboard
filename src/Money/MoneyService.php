<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\DB\Database;
use Dashboard\Knowledge\PageService;
use Dashboard\Support\Clock;
use Dashboard\Support\Csv;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Accounts, signed transactions, and a weekly review page in the vault.
 * Negative amounts are money out.
 */
final class MoneyService
{
    public const KINDS = ['checking', 'savings', 'credit', 'cash', 'investment', 'other'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly PageService $pages,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function accounts(int $userId, bool $includeArchived = false): array
    {
        $sql = 'SELECT * FROM money_accounts WHERE user_id = ?';
        if (!$includeArchived) {
            $sql .= ' AND archived_at IS NULL';
        }
        $sql .= ' ORDER BY name ASC';
        return array_map([$this, 'formatAccount'], $this->db->fetchAll($sql, [$userId]));
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createAccount(int $userId, array $input): array
    {
        $name = $this->cleanName($input['name'] ?? null);
        $kind = $this->cleanKind($input['kind'] ?? 'checking');
        $balance = $this->cleanAmount($input['balance'] ?? 0, 'balance');
        if ($this->db->fetchOne('SELECT 1 FROM money_accounts WHERE user_id = ? AND name = ?', [$userId, $name]) !== null) {
            throw ValidationException::withFields(['name' => 'An account with that name already exists.']);
        }
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO money_accounts (user_id, name, kind, balance) VALUES (?, ?, ?, ?) RETURNING id',
            [$userId, $name, $kind, $balance]
        );
        $this->touchSnapshot($userId);
        return $this->account($userId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateAccount(int $userId, int $id, array $input): array
    {
        $account = $this->account($userId, $id);
        $name = array_key_exists('name', $input) ? $this->cleanName($input['name']) : (string) $account['name'];
        $kind = array_key_exists('kind', $input) ? $this->cleanKind($input['kind']) : (string) $account['kind'];
        $balance = array_key_exists('balance', $input) ? $this->cleanAmount($input['balance'], 'balance') : (float) $account['balance'];
        $this->db->execute(
            "UPDATE money_accounts SET name = ?, kind = ?, balance = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$name, $kind, $balance, $id, $userId]
        );
        $this->touchSnapshot($userId);
        return $this->account($userId, $id);
    }

    public function archiveAccount(int $userId, int $id): void
    {
        $this->account($userId, $id);
        $this->db->execute(
            "UPDATE money_accounts SET archived_at = (now() AT TIME ZONE 'UTC'), updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$id, $userId]
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function addTransaction(int $userId, array $input, string $source = 'manual'): array
    {
        $account = $this->account($userId, (int) ($input['account_id'] ?? 0));
        $amount = $this->cleanAmount($input['amount'] ?? null, 'amount');
        $date = $this->cleanDate($input['date'] ?? $input['posted_on'] ?? null);
        $payee = $this->optionalText($input['payee'] ?? null, 160);
        $category = $this->optionalText($input['category'] ?? null, 80) ?? 'Uncategorized';
        $note = $this->optionalText($input['note'] ?? null, 2000);
        $dedupe = isset($input['dedupe_key']) && $input['dedupe_key'] !== '' ? mb_substr((string) $input['dedupe_key'], 0, 160) : null;
        if (!in_array($source, ['manual', 'import', 'ai'], true)) {
            $source = 'manual';
        }
        $params = [$userId, (int) $account['id'], $date, $amount, $payee, $category, $note, $dedupe, $source];
        $sql = $dedupe === null
            ? 'INSERT INTO money_transactions (user_id, account_id, posted_on, amount, payee, category, note, dedupe_key, source)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id'
            : 'INSERT INTO money_transactions (user_id, account_id, posted_on, amount, payee, category, note, dedupe_key, source)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT (user_id, dedupe_key) DO UPDATE
                  SET account_id = EXCLUDED.account_id, posted_on = EXCLUDED.posted_on, amount = EXCLUDED.amount,
                      payee = EXCLUDED.payee, category = EXCLUDED.category, note = EXCLUDED.note, source = EXCLUDED.source
               RETURNING id';
        $id = (int) $this->db->fetchScalar($sql, $params);
        return $this->transaction($userId, $id);
    }

    /**
     * Monarch-style CSV: Date, Amount, Merchant, Category, Account.
     * Amount is signed (negative = spent).
     *
     * @return array{imported: int, accounts: int}
     */
    public function importCsv(int $userId, string $csv): array
    {
        $rows = Csv::assocRows($csv);
        if ($rows === []) {
            throw ValidationException::withFields(['csv' => 'The file has no rows.']);
        }
        $imported = 0;
        $created = 0;
        foreach ($rows as $row) {
            $date = $this->flexDate($row['date'] ?? '');
            $amount = $this->flexAmount($row['amount'] ?? '');
            if ($date === null || $amount === null) {
                continue;
            }
            $accountName = mb_substr(trim($row['account'] ?? '') ?: 'Imported', 0, 80);
            $account = $this->findOrCreateAccount($userId, $accountName, $row['account type'] ?? 'checking');
            if ($account['created']) {
                $created++;
            }
            $payee = trim($row['merchant'] ?? $row['payee'] ?? $row['original statement'] ?? '') ?: null;
            $category = trim($row['category'] ?? '') ?: 'Uncategorized';
            $note = trim($row['notes'] ?? $row['note'] ?? '') ?: null;
            $dedupe = 'monarch:' . substr(hash('sha256', json_encode([$date, $amount, $payee, $category, $accountName], JSON_THROW_ON_ERROR)), 0, 40);
            $this->addTransaction($userId, [
                'account_id' => $account['id'],
                'date'       => $date,
                'amount'     => $amount,
                'payee'      => $payee,
                'category'   => $category,
                'note'       => $note,
                'dedupe_key' => $dedupe,
            ], 'import');
            $imported++;
        }
        if ($imported === 0) {
            throw ValidationException::withFields(['csv' => 'No dated amount rows were found. Expected Date and Amount columns.']);
        }
        $this->touchSnapshot($userId);
        return ['imported' => $imported, 'accounts' => $created];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(int $userId, ?string $from = null, ?string $to = null, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $sql = 'SELECT t.*, a.name AS account_name FROM money_transactions t
                JOIN money_accounts a ON a.id = t.account_id
                WHERE t.user_id = ?';
        $params = [$userId];
        if ($from !== null && $from !== '') {
            $sql .= ' AND t.posted_on >= ?';
            $params[] = $from;
        }
        if ($to !== null && $to !== '') {
            $sql .= ' AND t.posted_on <= ?';
            $params[] = $to;
        }
        $sql .= " ORDER BY t.posted_on DESC, t.id DESC LIMIT {$limit}";
        return array_map(static function (array $row): array {
            return [
                'id'       => (int) $row['id'],
                'account'  => (string) $row['account_name'],
                'date'     => (string) $row['posted_on'],
                'amount'   => (float) $row['amount'],
                'payee'    => $row['payee'] !== null ? (string) $row['payee'] : null,
                'category' => (string) $row['category'],
                'note'     => $row['note'] !== null ? (string) $row['note'] : null,
            ];
        }, $this->db->fetchAll($sql, $params));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $userId, string $from, string $to): array
    {
        $spent = (float) $this->db->fetchScalar(
            'SELECT COALESCE(SUM(-amount), 0) FROM money_transactions
             WHERE user_id = ? AND posted_on >= ? AND posted_on <= ? AND amount < 0',
            [$userId, $from, $to]
        );
        $categories = array_map(static fn (array $row): array => [
            'category' => (string) $row['category'],
            'total'    => (float) $row['total'],
        ], $this->db->fetchAll(
            'SELECT category, SUM(-amount) AS total FROM money_transactions
             WHERE user_id = ? AND posted_on >= ? AND posted_on <= ? AND amount < 0
             GROUP BY category ORDER BY total DESC',
            [$userId, $from, $to]
        ));
        $accounts = $this->accounts($userId);
        $net = 0.0;
        foreach ($accounts as $account) {
            $net += $account['kind'] === 'credit' ? -(float) $account['balance'] : (float) $account['balance'];
        }
        $snapshots = array_map(static fn (array $row): array => [
            'date' => (string) $row['as_of'],
            'net'  => (float) $row['net_worth'],
        ], $this->db->fetchAll(
            'SELECT as_of, net_worth FROM money_snapshots WHERE user_id = ? AND as_of >= ? AND as_of <= ? ORDER BY as_of ASC',
            [$userId, $from, $to]
        ));
        return [
            'from'       => $from,
            'to'         => $to,
            'spent'      => $spent,
            'categories' => $categories,
            'accounts'   => $accounts,
            'net_worth'  => $net,
            'snapshots'  => $snapshots,
        ];
    }

    /**
     * @return array<string, mixed> the vault page
     */
    public function weeklyReview(int $userId): array
    {
        $today = $this->clock->today();
        $from = Clock::shiftDate($today, 6);
        $summary = $this->summary($userId, $from, $today);
        $lines = [
            '# Weekly review ' . $today,
            '',
            'Spent **$' . number_format($summary['spent'], 2) . '** from ' . $from . ' to ' . $today . '.',
            '',
            '## Categories',
        ];
        if ($summary['categories'] === []) {
            $lines[] = '- No outflows this week.';
        }
        foreach ($summary['categories'] as $cat) {
            $lines[] = '- ' . $cat['category'] . ' — $' . number_format($cat['total'], 2);
        }
        $lines[] = '';
        $lines[] = '## Net worth';
        $lines[] = '$' . number_format($summary['net_worth'], 2);
        $lines[] = '';
        $slug = 'weekly-review-' . $today;
        return $this->pages->upsertSlug($userId, $slug, 'Weekly review ' . $today, implode("\n", $lines), 'review');
    }

    /**
     * @return array<string, mixed>
     */
    private function account(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Account not found.');
        }
        return $this->formatAccount($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(int $userId, int $id): array
    {
        $rows = $this->transactions($userId);
        foreach ($rows as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        $one = $this->db->fetchOne(
            'SELECT t.*, a.name AS account_name FROM money_transactions t
             JOIN money_accounts a ON a.id = t.account_id WHERE t.id = ? AND t.user_id = ?',
            [$id, $userId]
        );
        if ($one === null) {
            throw new NotFoundException('Transaction not found.');
        }
        return [
            'id'       => (int) $one['id'],
            'account'  => (string) $one['account_name'],
            'date'     => (string) $one['posted_on'],
            'amount'   => (float) $one['amount'],
            'payee'    => $one['payee'] !== null ? (string) $one['payee'] : null,
            'category' => (string) $one['category'],
            'note'     => $one['note'] !== null ? (string) $one['note'] : null,
        ];
    }

    /**
     * @return array{id: int, created: bool}
     */
    private function findOrCreateAccount(int $userId, string $name, string $kindHint): array
    {
        $name = $this->cleanName($name);
        $row = $this->db->fetchOne('SELECT id FROM money_accounts WHERE user_id = ? AND name = ?', [$userId, $name]);
        if ($row !== null) {
            return ['id' => (int) $row['id'], 'created' => false];
        }
        $kind = strtolower(trim($kindHint));
        if (!in_array($kind, self::KINDS, true)) {
            $kind = str_contains($kind, 'credit') ? 'credit' : 'checking';
        }
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO money_accounts (user_id, name, kind) VALUES (?, ?, ?) RETURNING id',
            [$userId, $name, $kind]
        );
        return ['id' => $id, 'created' => true];
    }

    private function touchSnapshot(int $userId, ?float $net = null): void
    {
        if ($net === null) {
            $net = 0.0;
            foreach ($this->accounts($userId) as $account) {
                $net += $account['kind'] === 'credit' ? -(float) $account['balance'] : (float) $account['balance'];
            }
        }
        $this->db->execute(
            'INSERT INTO money_snapshots (user_id, as_of, net_worth) VALUES (?, ?, ?)
             ON CONFLICT (user_id, as_of) DO UPDATE SET net_worth = EXCLUDED.net_worth',
            [$userId, $this->clock->today(), $net]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatAccount(array $row): array
    {
        return [
            'id'       => (int) $row['id'],
            'name'     => (string) $row['name'],
            'kind'     => (string) $row['kind'],
            'balance'  => (float) $row['balance'],
            'archived' => $row['archived_at'] !== null,
        ];
    }

    private function cleanName(mixed $name): string
    {
        $name = trim((string) $name);
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withFields(['name' => 'Account name must be 1–80 characters.']);
        }
        return $name;
    }

    private function cleanKind(mixed $kind): string
    {
        $kind = strtolower(trim((string) $kind));
        if (!in_array($kind, self::KINDS, true)) {
            throw ValidationException::withFields(['kind' => 'Unknown account kind.']);
        }
        return $kind;
    }

    private function cleanAmount(mixed $value, string $field): float
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields([$field => 'Enter a number.']);
        }
        return round((float) $value, 2);
    }

    private function cleanDate(mixed $value): string
    {
        try {
            return Clock::normalizeDate((string) $value);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withFields(['date' => $e->getMessage()]);
        }
    }

    private function optionalText(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        return mb_substr($text, 0, $max);
    }

    private function flexDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $fmt, $raw);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('Y-m-d');
            }
        }
        return null;
    }

    private function flexAmount(string $raw): ?float
    {
        $raw = trim(str_replace([',', '$', '('], '', $raw));
        $raw = str_replace(')', '', $raw);
        if ($raw === '' || !is_numeric($raw)) {
            return null;
        }
        return round((float) $raw, 2);
    }
}
