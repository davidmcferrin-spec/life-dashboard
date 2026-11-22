<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\CloudStorage\CredentialCrypto;
use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Link, map, and sync Plaid items. The access token stays encrypted.
 * Mapping does not copy a balance or rewrite transactions.
 */
final class PlaidSync
{
    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly MoneyService $money,
        private readonly MoneyPlanService $plan,
        private readonly PlaidSettings $settings,
        private readonly PlaidTransport $transport,
    ) {}

    public static function make(Database $db, Clock $clock, MoneyService $money, MoneyPlanService $plan, ?PlaidTransport $transport = null): self
    {
        return new self(
            $db,
            $clock,
            $money,
            $plan,
            new PlaidSettings($db),
            $transport ?? new StreamPlaidTransport(),
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array{client_id_hint: string, secret_hint: string, environment: string, configured: bool}
     */
    public function saveSettings(int $userId, array $input): array
    {
        return $this->realSettings()->save($userId, $input);
    }

    /**
     * @return array{client_id_hint: string, secret_hint: string, environment: string, configured: bool}
     */
    public function settingsView(int $userId): array
    {
        return $this->realSettings()->view($userId);
    }

    /**
     * @return array{link_token: string, expiration: ?string}
     */
    public function linkToken(int $userId, ?int $itemRowId = null): array
    {
        $client = $this->client($userId);
        $access = null;
        if ($itemRowId !== null) {
            $access = $this->decryptToken($this->itemRow($userId, $itemRowId));
        }
        try {
            $result = $client->createLinkToken('user-' . $userId, $access);
        } catch (PlaidException $e) {
            throw ValidationException::withFields(['plaid' => $e->getMessage()]);
        }
        $token = (string) ($result['link_token'] ?? '');
        if ($token === '') {
            throw new PlaidException('LINK_TOKEN', 'Plaid did not return a link token.');
        }

        return [
            'link_token' => $token,
            'expiration' => isset($result['expiration']) ? (string) $result['expiration'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function exchange(int $userId, array $input): array
    {
        $public = trim((string) ($input['public_token'] ?? ''));
        if ($public === '' || strlen($public) > 500 || preg_match('/[\r\n\0]/', $public) === 1) {
            throw ValidationException::withFields(['public_token' => 'Plaid did not return a public token.']);
        }
        $institution = trim((string) ($input['institution_name'] ?? ''));
        if ($institution === '') {
            $institution = 'Bank';
        }
        $institution = mb_substr($institution, 0, 120);
        $institutionId = trim((string) ($input['institution_id'] ?? ''));
        $institutionId = $institutionId === '' ? null : mb_substr($institutionId, 0, 80);
        try {
            $exchanged = $this->client($userId)->exchangePublicToken($public);
        } catch (PlaidException $e) {
            throw ValidationException::withFields(['plaid' => $e->getMessage()]);
        }
        $access = (string) ($exchanged['access_token'] ?? '');
        $itemId = (string) ($exchanged['item_id'] ?? '');
        if ($access === '' || $itemId === '') {
            throw new PlaidException('EXCHANGE', 'Plaid did not return an item.');
        }
        $encrypted = CredentialCrypto::encryptJson(['access_token' => $access]);
        $existing = $this->db->fetchOne(
            'SELECT id FROM money_plaid_items WHERE user_id = ? AND item_id = ?',
            [$userId, $itemId]
        );
        if ($existing === null) {
            $rowId = (int) $this->db->fetchScalar(
                'INSERT INTO money_plaid_items (user_id, item_id, institution_id, institution_name, access_token_encrypted)
                 VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$userId, $itemId, $institutionId, $institution, $encrypted]
            );
        } else {
            $rowId = (int) $existing['id'];
            $this->db->execute(
                'UPDATE money_plaid_items SET institution_id = ?, institution_name = ?, access_token_encrypted = ?,
                    status = ?, sync_error = NULL, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [$institutionId, $institution, $encrypted, 'good', $rowId, $userId]
            );
        }
        try {
            $listed = $this->client($userId)->accounts($access);
        } catch (PlaidException $e) {
            throw ValidationException::withFields(['plaid' => $e->getMessage()]);
        }
        $this->storeAccounts($userId, $rowId, $listed);

        return $this->item($userId, $rowId);
    }

    /**
     * @return array{items: list<array<string, mixed>>}
     */
    public function items(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM money_plaid_items WHERE user_id = ? ORDER BY institution_name ASC, id ASC',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->presentItem($row, $this->accountRows($userId, (int) $row['id']));
        }

        return ['items' => $out];
    }

    /**
     * @return array<string, mixed>
     */
    public function syncStatus(int $userId): array
    {
        $settings = $this->settingsView($userId);
        $items = $this->items($userId)['items'];
        $accounts = [];
        foreach ($this->money->accounts($userId) as $account) {
            $accounts[] = [
                'id'             => (int) $account['id'],
                'name'           => (string) $account['name'],
                'last_synced_at' => $account['last_synced_at'],
                'stale'          => (bool) $account['stale'],
                'live_sync'      => ($account['sync_provider'] ?? null) === 'plaid' || $this->linkedAccount((int) $account['id'], $userId),
            ];
        }

        return [
            'configured'   => $settings['configured'],
            'environment'  => $settings['configured'] ? $settings['environment'] : null,
            'items'        => $items,
            'accounts'     => $accounts,
            'sync_errors'  => $this->syncErrors($userId),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function syncErrors(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id, institution_name, status, sync_error, last_synced_at
             FROM money_plaid_items WHERE user_id = ? AND sync_error IS NOT NULL AND sync_error <> \'\'
             ORDER BY id ASC',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'item_id'        => (int) $row['id'],
                'institution'    => (string) $row['institution_name'],
                'status'         => (string) $row['status'],
                'sync_error'     => (string) $row['sync_error'],
                'last_synced_at' => $row['last_synced_at'] !== null ? (string) $row['last_synced_at'] : null,
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(int $userId, int $itemRowId): array
    {
        $row = $this->itemRow($userId, $itemRowId);
        $token = $this->decryptToken($row);
        try {
            $this->client($userId)->removeItem($token);
        } catch (PlaidException $e) {
            if ($e->errorCode !== 'ITEM_NOT_FOUND') {
                throw ValidationException::withFields(['plaid' => $e->getMessage()]);
            }
        }
        $linked = $this->db->fetchAll(
            'SELECT account_id FROM money_plaid_accounts WHERE item_row_id = ? AND user_id = ? AND account_id IS NOT NULL',
            [$itemRowId, $userId]
        );
        foreach ($linked as $link) {
            $this->db->execute(
                "UPDATE money_accounts SET sync_provider = NULL, updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE id = ? AND user_id = ? AND sync_provider = 'plaid'",
                [(int) $link['account_id'], $userId]
            );
        }
        $this->db->execute('DELETE FROM money_plaid_items WHERE id = ? AND user_id = ?', [$itemRowId, $userId]);

        return ['removed' => true, 'item_id' => $itemRowId];
    }

    /**
     * Update mode finished in the browser. The stored token is unchanged.
     *
     * @return array<string, mixed>
     */
    public function markRelinked(int $userId, int $itemRowId): array
    {
        $this->itemRow($userId, $itemRowId);
        $this->db->execute(
            'UPDATE money_plaid_items SET status = ?, sync_error = NULL, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            ['good', $itemRowId, $userId]
        );

        return $this->item($userId, $itemRowId);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function mapAccount(int $userId, int $plaidAccountRowId, array $input): array
    {
        $plaid = $this->plaidAccountRow($userId, $plaidAccountRowId);
        $create = !empty($input['create']);
        $kept = 0.0;
        $accountId = isset($input['account_id']) && $input['account_id'] !== null && $input['account_id'] !== ''
            ? (int) $input['account_id']
            : null;
        if ($create) {
            $created = $this->money->createAccount($userId, [
                'name'    => (string) $plaid['name'],
                'kind'    => PlaidClient::dashboardKind((string) ($plaid['plaid_type'] ?? ''), (string) ($plaid['plaid_subtype'] ?? '')),
                'balance' => 0,
                'last4'   => $plaid['mask'],
            ]);
            $accountId = (int) $created['id'];
            $this->db->execute(
                'UPDATE money_accounts SET last_synced_at = NULL, sync_provider = ?, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                ['plaid', $accountId, $userId]
            );
        } elseif ($accountId === null) {
            throw ValidationException::withFields(['account_id' => 'Choose an account, or create one.']);
        } else {
            $this->money->account($userId, $accountId);
            $kept = $this->balanceOf($userId, $accountId);
            $this->db->execute(
                "UPDATE money_accounts SET sync_provider = 'plaid', updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE id = ? AND user_id = ?",
                [$accountId, $userId]
            );
        }
        if (!$create && $plaid['account_id'] !== null && $plaid['account_id'] !== '' && (int) $plaid['account_id'] !== $accountId) {
            $this->db->execute(
                "UPDATE money_accounts SET sync_provider = NULL WHERE id = ? AND user_id = ? AND sync_provider = 'plaid'",
                [(int) $plaid['account_id'], $userId]
            );
        }
        try {
            $this->db->execute(
                'UPDATE money_plaid_accounts SET account_id = ? WHERE id = ? AND user_id = ?',
                [$accountId, $plaidAccountRowId, $userId]
            );
        } catch (\PDOException $e) {
            if (str_contains($e->getMessage(), 'idx_money_plaid_accounts_dashboard') || str_contains($e->getMessage(), '23505')) {
                throw ValidationException::withFields(['account_id' => 'That account is already linked to a bank account.']);
            }
            throw $e;
        }
        if (!$create && $accountId !== null && abs($this->balanceOf($userId, $accountId) - $kept) > 0.001) {
            throw new \RuntimeException('Mapping changed an existing balance.');
        }

        return $this->presentPlaidAccount($this->plaidAccountRow($userId, $plaidAccountRowId));
    }

    /**
     * @return array{accounts: list<array<string, mixed>>}
     */
    public function refresh(int $userId, ?int $accountId): array
    {
        $accounts = $accountId === null
            ? $this->money->accounts($userId)
            : [$this->money->account($userId, $accountId)];
        $links = $this->linksByDashboardAccount($userId);
        $items = [];
        $pending = [];
        foreach ($accounts as $account) {
            $id = (int) $account['id'];
            $link = $links[$id] ?? null;
            if ($link === null) {
                $pending[$id] = $this->offline($account);
                continue;
            }
            $items[(int) $link['item_row_id']] = true;
            $pending[$id] = $id;
        }
        $errors = [];
        foreach (array_keys($items) as $itemRowId) {
            try {
                $this->syncItem($userId, (int) $itemRowId);
            } catch (PlaidException $e) {
                $this->recordError($userId, (int) $itemRowId, $e);
                $errors[(int) $itemRowId] = $e->getMessage();
            }
        }
        $freshAccounts = [];
        foreach ($this->money->accounts($userId) as $account) {
            $freshAccounts[(int) $account['id']] = $account;
        }
        $out = [];
        foreach ($pending as $id => $marker) {
            if (is_array($marker)) {
                $out[] = $marker;
                continue;
            }
            $account = $freshAccounts[$id] ?? $this->money->account($userId, $id);
            $link = $links[$id] ?? null;
            $itemRowId = $link !== null ? (int) $link['item_row_id'] : 0;
            if (isset($errors[$itemRowId])) {
                $out[] = [
                    'id'             => $id,
                    'name'           => (string) $account['name'],
                    'last_synced_at' => $account['last_synced_at'],
                    'stale'          => (bool) $account['stale'],
                    'refreshed'      => false,
                    'live_sync'      => true,
                    'message'        => $errors[$itemRowId],
                ];
                continue;
            }
            $out[] = [
                'id'             => $id,
                'name'           => (string) $account['name'],
                'last_synced_at' => $account['last_synced_at'],
                'stale'          => (bool) $account['stale'],
                'refreshed'      => true,
                'live_sync'      => true,
                'message'        => null,
            ];
        }

        return ['accounts' => $out];
    }

    /**
     * @return array{users: int, items: int, failed: int}
     */
    public function syncAll(): array
    {
        $users = $this->db->fetchAll('SELECT DISTINCT user_id FROM money_plaid_items');
        $items = 0;
        $failed = 0;
        foreach ($users as $user) {
            $userId = (int) $user['user_id'];
            foreach ($this->db->fetchAll('SELECT id FROM money_plaid_items WHERE user_id = ?', [$userId]) as $item) {
                $items++;
                try {
                    $this->syncItem($userId, (int) $item['id']);
                } catch (PlaidException $e) {
                    $this->recordError($userId, (int) $item['id'], $e);
                    $failed++;
                } catch (\Throwable) {
                    $failed++;
                }
            }
        }

        return ['users' => count($users), 'items' => $items, 'failed' => $failed];
    }

    private function syncItem(int $userId, int $itemRowId): void
    {
        $row = $this->itemRow($userId, $itemRowId);
        $token = $this->decryptToken($row);
        $client = $this->client($userId);
        $balances = $client->balances($token);
        $this->applyBalances($userId, $itemRowId, $balances);
        $cursor = $row['transactions_cursor'] !== null && $row['transactions_cursor'] !== ''
            ? (string) $row['transactions_cursor']
            : null;
        $guard = 0;
        do {
            $page = $client->transactionsSync($token, $cursor);
            $this->applyTransactions($userId, $itemRowId, $page);
            $cursor = isset($page['next_cursor']) ? (string) $page['next_cursor'] : $cursor;
            $this->db->execute(
                'UPDATE money_plaid_items SET transactions_cursor = ? WHERE id = ? AND user_id = ?',
                [$cursor, $itemRowId, $userId]
            );
            $more = !empty($page['has_more']);
            $guard++;
        } while ($more && $guard < 20);
        try {
            $this->applyLiabilities($userId, $itemRowId, $client->liabilities($token));
        } catch (PlaidException $e) {
            if (!in_array($e->errorCode, ['PRODUCTS_NOT_SUPPORTED', 'PRODUCT_NOT_READY', 'NO_LIABILITY_ACCOUNTS', 'INVALID_PRODUCT'], true)) {
                throw $e;
            }
        }
        $stamp = $this->clock->utcString($this->clock->now());
        $this->db->execute(
            'UPDATE money_plaid_items SET status = ?, sync_error = NULL, last_synced_at = ?, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            ['good', $stamp, $itemRowId, $userId]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyBalances(int $userId, int $itemRowId, array $payload): void
    {
        $mapped = [];
        foreach ($this->accountRows($userId, $itemRowId) as $row) {
            if ($row['account_id'] === null || $row['account_id'] === '') {
                continue;
            }
            $mapped[(string) $row['plaid_account_id']] = $row;
        }
        foreach ($payload['accounts'] ?? [] as $account) {
            if (!is_array($account)) {
                continue;
            }
            $plaidId = (string) ($account['account_id'] ?? '');
            $link = $mapped[$plaidId] ?? null;
            if ($link === null) {
                continue;
            }
            $balances = is_array($account['balances'] ?? null) ? $account['balances'] : [];
            $current = isset($balances['current']) && is_numeric($balances['current']) ? round((float) $balances['current'], 2) : null;
            if ($current === null) {
                continue;
            }
            $available = isset($balances['available']) && is_numeric($balances['available']) ? round((float) $balances['available'], 2) : null;
            $limit = isset($balances['limit']) && is_numeric($balances['limit']) ? round((float) $balances['limit'], 2) : null;
            $kind = PlaidClient::dashboardKind((string) ($account['type'] ?? $link['plaid_type'] ?? ''), (string) ($account['subtype'] ?? $link['plaid_subtype'] ?? ''));
            $stamp = $this->clock->utcString($this->clock->now());
            if ($kind === 'credit') {
                $this->db->execute(
                    'UPDATE money_accounts SET balance = ?, available_balance = ?, available_credit = COALESCE(?, available_credit),
                        credit_limit = COALESCE(?, credit_limit), last_synced_at = ?, sync_provider = ?, updated_at = (now() AT TIME ZONE \'UTC\')
                     WHERE id = ? AND user_id = ?',
                    [$current, $available, $available, $limit, $stamp, 'plaid', (int) $link['account_id'], $userId]
                );
            } else {
                $this->db->execute(
                    'UPDATE money_accounts SET balance = ?, available_balance = ?, last_synced_at = ?, sync_provider = ?,
                        updated_at = (now() AT TIME ZONE \'UTC\')
                     WHERE id = ? AND user_id = ?',
                    [$current, $available, $stamp, 'plaid', (int) $link['account_id'], $userId]
                );
            }
        }
        $this->money->recordSnapshot($userId);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function applyTransactions(int $userId, int $itemRowId, array $page): void
    {
        $mapped = [];
        foreach ($this->accountRows($userId, $itemRowId) as $row) {
            if ($row['account_id'] === null || $row['account_id'] === '') {
                continue;
            }
            $mapped[(string) $row['plaid_account_id']] = (int) $row['account_id'];
        }
        foreach ($page['added'] ?? [] as $txn) {
            if (is_array($txn)) {
                $this->importTransaction($userId, $mapped, $txn, false);
            }
        }
        foreach ($page['modified'] ?? [] as $txn) {
            if (is_array($txn)) {
                $this->importTransaction($userId, $mapped, $txn, true);
            }
        }
    }

    /**
     * @param array<string, int> $mapped
     * @param array<string, mixed> $txn
     */
    private function importTransaction(int $userId, array $mapped, array $txn, bool $modified): void
    {
        if (!empty($txn['pending'])) {
            return;
        }
        $plaidId = trim((string) ($txn['transaction_id'] ?? ''));
        $accountId = $mapped[(string) ($txn['account_id'] ?? '')] ?? null;
        if ($plaidId === '' || $accountId === null || !isset($txn['amount']) || !is_numeric($txn['amount'])) {
            return;
        }
        $amount = PlaidClient::signedAmount((float) $txn['amount']);
        $date = substr((string) ($txn['date'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return;
        }
        $payee = trim((string) ($txn['merchant_name'] ?? $txn['name'] ?? ''));
        $payee = $payee === '' ? null : mb_substr($payee, 0, 160);
        $category = PlaidClient::categoryLabel($txn);
        $existing = $this->db->fetchOne(
            'SELECT id FROM money_transactions WHERE user_id = ? AND plaid_transaction_id = ?',
            [$userId, $plaidId]
        );
        if ($existing !== null) {
            $this->updateImported($userId, (int) $existing['id'], $amount, $date, $payee, $category);
            return;
        }
        if ($modified) {
            return;
        }
        $written = $this->money->addTransaction($userId, [
            'account_id' => $accountId,
            'amount'     => $amount,
            'date'       => $date,
            'payee'      => $payee,
            'category'   => $category,
            'dedupe_key' => mb_substr('plaid:' . $plaidId, 0, 160),
        ], 'import');
        $this->db->execute(
            'UPDATE money_transactions SET plaid_transaction_id = ? WHERE id = ? AND user_id = ? AND plaid_transaction_id IS NULL',
            [$plaidId, (int) $written['id'], $userId]
        );
    }

    private function updateImported(int $userId, int $transactionId, float $amount, string $date, ?string $payee, string $category): void
    {
        $override = $this->db->fetchOne(
            'SELECT id FROM money_category_overrides WHERE user_id = ? AND transaction_id = ?',
            [$userId, $transactionId]
        );
        if ($override !== null) {
            $this->db->execute(
                'UPDATE money_transactions SET amount = ?, posted_on = ?, payee = ? WHERE id = ? AND user_id = ?',
                [$amount, $date, $payee, $transactionId, $userId]
            );
            return;
        }
        $categoryId = $this->money->ensureCategory($userId, $category, $amount);
        $this->db->execute(
            'UPDATE money_transactions SET amount = ?, posted_on = ?, payee = ?, category = ?, category_id = ?
             WHERE id = ? AND user_id = ?',
            [$amount, $date, $payee, $category, $categoryId, $transactionId, $userId]
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyLiabilities(int $userId, int $itemRowId, array $payload): void
    {
        $groups = $payload['liabilities'] ?? [];
        if (!is_array($groups)) {
            return;
        }
        $mapped = [];
        foreach ($this->accountRows($userId, $itemRowId) as $row) {
            if ($row['account_id'] === null || $row['account_id'] === '') {
                continue;
            }
            $mapped[(string) $row['plaid_account_id']] = (int) $row['account_id'];
        }
        foreach (['credit', 'student', 'mortgage'] as $kind) {
            foreach ($groups[$kind] ?? [] as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $dashboardId = $mapped[(string) ($row['account_id'] ?? '')] ?? null;
                if ($dashboardId === null) {
                    continue;
                }
                $debt = $this->db->fetchOne(
                    'SELECT id FROM money_debts WHERE user_id = ? AND account_id = ? AND archived_at IS NULL ORDER BY id ASC LIMIT 1',
                    [$userId, $dashboardId]
                );
                if ($debt === null) {
                    continue;
                }
                $patch = [];
                $apr = $this->apr($row);
                if ($apr !== null) {
                    $patch['apr_percent'] = $apr;
                }
                $minimum = $row['minimum_payment_amount'] ?? null;
                if (is_numeric($minimum)) {
                    $patch['minimum_payment'] = round((float) $minimum, 2);
                }
                $due = $row['next_payment_due_date'] ?? null;
                if (is_string($due) && preg_match('/^\d{4}-\d{2}-(\d{2})$/', $due, $m) === 1) {
                    $patch['due_day'] = (int) $m[1];
                }
                if ($patch !== []) {
                    $this->plan->updateDebt($userId, (int) $debt['id'], $patch);
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function apr(array $row): ?float
    {
        $aprs = $row['aprs'] ?? [];
        if (!is_array($aprs)) {
            return null;
        }
        $chosen = null;
        foreach ($aprs as $apr) {
            if (!is_array($apr) || !isset($apr['apr_percentage']) || !is_numeric($apr['apr_percentage'])) {
                continue;
            }
            if (($apr['apr_type'] ?? '') === 'purchase_apr') {
                return round((float) $apr['apr_percentage'], 4);
            }
            $chosen ??= round((float) $apr['apr_percentage'], 4);
        }

        return $chosen;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeAccounts(int $userId, int $itemRowId, array $payload): void
    {
        foreach ($payload['accounts'] ?? [] as $account) {
            if (!is_array($account)) {
                continue;
            }
            $plaidId = trim((string) ($account['account_id'] ?? ''));
            if ($plaidId === '') {
                continue;
            }
            $name = mb_substr(trim((string) ($account['name'] ?? $account['official_name'] ?? 'Account')) ?: 'Account', 0, 120);
            $mask = isset($account['mask']) && preg_match('/^\d{4}$/', (string) $account['mask']) === 1 ? (string) $account['mask'] : null;
            $type = mb_substr((string) ($account['type'] ?? ''), 0, 32);
            $subtype = mb_substr((string) ($account['subtype'] ?? ''), 0, 32);
            $this->db->execute(
                'INSERT INTO money_plaid_accounts (user_id, item_row_id, plaid_account_id, name, mask, plaid_type, plaid_subtype)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (user_id, plaid_account_id) DO UPDATE SET
                    name = EXCLUDED.name, mask = EXCLUDED.mask, plaid_type = EXCLUDED.plaid_type, plaid_subtype = EXCLUDED.plaid_subtype',
                [$userId, $itemRowId, $plaidId, $name, $mask, $type !== '' ? $type : null, $subtype !== '' ? $subtype : null]
            );
        }
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    private function offline(array $account): array
    {
        return [
            'id'             => (int) $account['id'],
            'name'           => (string) $account['name'],
            'last_synced_at' => $account['last_synced_at'],
            'stale'          => (bool) $account['stale'],
            'refreshed'      => false,
            'live_sync'      => false,
            'message'        => 'No live sync is connected for this account.',
        ];
    }

    private function recordError(int $userId, int $itemRowId, PlaidException $e): void
    {
        $status = $e->errorCode === 'ITEM_LOGIN_REQUIRED' ? 'login_required' : 'error';
        $this->db->execute(
            'UPDATE money_plaid_items SET status = ?, sync_error = ?, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            [$status, $e->getMessage(), $itemRowId, $userId]
        );
    }

    private function client(int $userId): PlaidClient
    {
        $creds = $this->realSettings()->credentials($userId);
        if ($creds === null) {
            throw ValidationException::withFields(['plaid' => 'Save a Plaid client id and secret first.']);
        }

        return new PlaidClient($creds['client_id'], $creds['secret'], $creds['environment'], $this->transport);
    }

    private function realSettings(): PlaidSettings
    {
        return $this->settings;
    }

    /**
     * @return array<string, mixed>
     */
    private function item(int $userId, int $id): array
    {
        return $this->presentItem($this->itemRow($userId, $id), $this->accountRows($userId, $id));
    }

    /**
     * @return array<string, mixed>
     */
    private function itemRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_plaid_items WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Bank connection not found.');
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function decryptToken(array $row): string
    {
        try {
            $decoded = CredentialCrypto::decryptJson((string) $row['access_token_encrypted']);
        } catch (\RuntimeException) {
            throw new PlaidException('TOKEN', 'The saved bank token could not be read.');
        }
        $token = (string) ($decoded['access_token'] ?? '');
        if ($token === '') {
            throw new PlaidException('TOKEN', 'The saved bank token could not be read.');
        }

        return $token;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accountRows(int $userId, int $itemRowId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM money_plaid_accounts WHERE user_id = ? AND item_row_id = ? ORDER BY name ASC',
            [$userId, $itemRowId]
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $accounts
     * @return array<string, mixed>
     */
    private function presentItem(array $row, array $accounts): array
    {
        return [
            'id'                => (int) $row['id'],
            'item_id'           => (string) $row['item_id'],
            'institution_id'    => $row['institution_id'] !== null ? (string) $row['institution_id'] : null,
            'institution_name'  => (string) $row['institution_name'],
            'status'            => (string) $row['status'],
            'sync_error'        => $row['sync_error'] !== null && $row['sync_error'] !== '' ? (string) $row['sync_error'] : null,
            'last_synced_at'    => $row['last_synced_at'] !== null ? (string) $row['last_synced_at'] : null,
            'needs_login'       => (string) $row['status'] === 'login_required',
            'accounts'          => array_map(fn (array $account): array => $this->presentPlaidAccount($account), $accounts),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function presentPlaidAccount(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'plaid_account_id' => (string) $row['plaid_account_id'],
            'name'             => (string) $row['name'],
            'mask'             => $row['mask'] !== null ? (string) $row['mask'] : null,
            'type'             => $row['plaid_type'] !== null ? (string) $row['plaid_type'] : null,
            'subtype'          => $row['plaid_subtype'] !== null ? (string) $row['plaid_subtype'] : null,
            'account_id'       => $row['account_id'] !== null && $row['account_id'] !== '' ? (int) $row['account_id'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function plaidAccountRow(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM money_plaid_accounts WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Bank account not found.');
        }

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function linksByDashboardAccount(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM money_plaid_accounts WHERE user_id = ? AND account_id IS NOT NULL',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['account_id']] = $row;
        }

        return $out;
    }

    private function linkedAccount(int $accountId, int $userId): bool
    {
        return isset($this->linksByDashboardAccount($userId)[$accountId]);
    }

    private function balanceOf(int $userId, int $accountId): float
    {
        return (float) $this->db->fetchScalar(
            'SELECT balance FROM money_accounts WHERE id = ? AND user_id = ?',
            [$accountId, $userId]
        );
    }
}
