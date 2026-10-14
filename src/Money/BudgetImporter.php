<?php

declare(strict_types=1);

namespace Dashboard\Money;

use Dashboard\DB\Database;
use Dashboard\Knowledge\PageService;
use Dashboard\Support\Csv;
use Dashboard\Support\ValidationException;

/**
 * One-time copy of exported budget tabs into Postgres.
 * Column names are checked against the header row. A map.json file can rename them.
 * The creator-subscription name is passed in for this run only and is never stored.
 */
final class BudgetImporter
{
    /** @var array<string, list<string>> */
    private const TX_ALIASES = [
        'date'     => ['date', 'posted', 'posted on'],
        'account'  => ['account', 'account name'],
        'amount'   => ['amount'],
        'debit'    => ['debit', 'outflow', 'withdrawal'],
        'credit'   => ['credit', 'inflow', 'deposit'],
        'payee'    => ['description', 'payee', 'merchant', 'name'],
        'category' => ['category'],
        'note'     => ['note', 'notes', 'memo'],
        'id'       => ['id', 'transaction id'],
    ];

    /** @var array<string, list<string>> */
    private const MONTHS = [
        '1' => ['jan', 'january'],
        '2' => ['feb', 'february'],
        '3' => ['mar', 'march'],
        '4' => ['apr', 'april'],
        '5' => ['may'],
        '6' => ['jun', 'june'],
        '7' => ['jul', 'july'],
        '8' => ['aug', 'august'],
        '9' => ['sep', 'sept', 'september'],
        '10' => ['oct', 'october'],
        '11' => ['nov', 'november'],
        '12' => ['dec', 'december'],
    ];

    public function __construct(
        private readonly Database $db,
        private readonly MoneyService $money,
        private readonly MoneyPlanService $plan,
        private readonly PageService $pages,
    ) {}

    /**
     * @param array<string, mixed>|null $map
     * @return array<string, mixed>
     */
    public function importDirectory(int $userId, string $dir, string $creatorName, ?array $map = null): array
    {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            throw ValidationException::withFields(['dir' => 'That folder does not exist.']);
        }
        $map ??= self::loadMap($dir);
        $creatorName = trim($creatorName);
        $files = $this->resolve($dir, $map);
        $parsed = [];
        $aliased = 0;
        foreach ($files as $kind => $paths) {
            foreach ($paths as $meta) {
                $text = (string) file_get_contents($meta['path']);
                self::refuseAccountNumbers($meta['label'], $text);
                if ($creatorName !== '') {
                    $aliased += self::countSecret($text, $creatorName);
                    $text = AccountNumberGuard::alias($text, $creatorName);
                }
                $parsed[] = ['kind' => $kind, 'meta' => $meta, 'text' => $text];
            }
        }

        $counts = [
            'transactions'  => 0,
            'accounts'      => 0,
            'budget_lines'  => 0,
            'debts'         => 0,
            'sinking_funds' => 0,
            'snapshots'     => 0,
            'credit_scores' => 0,
            'retirement'    => 0,
            'notes'         => 0,
            'aliased'       => $aliased,
            'skipped'       => [],
        ];

        $this->db->transaction(function () use ($userId, $parsed, &$counts, $map): void {
            foreach ($parsed as $item) {
                $rows = Csv::assocRows($item['text']);
                $headers = self::headersOf($item['text']);
                match ($item['kind']) {
                    'transactions' => $counts['transactions'] += $this->transactions($userId, $rows, $headers, $map, $counts),
                    'budget' => $counts['budget_lines'] += $this->budget($userId, $rows, $headers, $item['meta']),
                    'debts' => $counts['debts'] += $this->debts($userId, $rows, $headers),
                    'sinking' => $counts['sinking_funds'] += $this->sinking($userId, $rows, $headers),
                    'networth' => $counts['snapshots'] += $this->netWorth($userId, $rows, $headers),
                    'credit' => $counts['credit_scores'] += $this->credit($userId, $rows, $headers),
                    'retirement' => $counts['retirement'] += $this->retirement($userId, $rows, $headers),
                    'notes' => $counts['notes'] += $this->notes($userId, $item['text']),
                    default => 0,
                };
            }
        });
        return $counts;
    }

    /**
     * @param list<string> $headers
     * @param array<string, list<string>> $aliases
     * @param list<string> $required
     * @return array<string, string> logical name => actual header key (lowercase)
     */
    public static function matchHeaders(array $headers, array $aliases, array $required, ?array $forced = null): array
    {
        $present = [];
        foreach ($headers as $header) {
            $present[self::key($header)] = self::key($header);
        }
        $found = [];
        $missing = [];
        foreach ($aliases as $logical => $names) {
            $wanted = $forced[$logical] ?? null;
            $candidates = $wanted !== null && $wanted !== '' ? [self::key((string) $wanted)] : array_map([self::class, 'key'], $names);
            $hit = null;
            foreach ($candidates as $candidate) {
                if (isset($present[$candidate])) {
                    $hit = $present[$candidate];
                    break;
                }
            }
            if ($hit === null) {
                if (in_array($logical, $required, true) || ($wanted !== null && $wanted !== '')) {
                    $missing[] = $logical . ($wanted !== null && $wanted !== '' ? " (column \"{$wanted}\")" : '');
                }
                continue;
            }
            $found[$logical] = $hit;
        }
        if ($missing !== []) {
            throw ValidationException::withFields([
                'columns' => 'Missing ' . implode(', ', $missing) . '. Headers in the file: ' . implode(', ', $headers) . '.',
            ]);
        }
        return $found;
    }

    public static function countSecret(string $text, string $secret): int
    {
        $secret = trim($secret);
        if ($secret === '' || $text === '') {
            return 0;
        }
        return preg_match_all('/' . preg_quote($secret, '/') . '/iu', $text) ?: 0;
    }

    public static function refuseAccountNumbers(string $label, string $csv): void
    {
        foreach (Csv::assocRows($csv) as $row) {
            foreach ($row as $value) {
                if (AccountNumberGuard::hasFullNumber((string) $value)) {
                    throw ValidationException::withFields([
                        'csv' => $label . ' contains a full account number. Remove it and keep last 4 only.',
                    ]);
                }
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function loadMap(string $dir): array
    {
        $path = $dir . '/map.json';
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            throw ValidationException::withFields(['map' => 'map.json must be a JSON object.']);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $map
     * @return array<string, list<array{path: string, label: string, year?: int}>>
     */
    private function resolve(string $dir, array $map): array
    {
        $out = [];
        $tx = $this->find($dir, $map['transactions']['file'] ?? null, ['transactions.csv']);
        if ($tx !== null) {
            $out['transactions'] = [['path' => $tx, 'label' => basename($tx)]];
        }
        $budgets = $map['budgets'] ?? null;
        if (is_array($budgets) && $budgets !== []) {
            foreach ($budgets as $spec) {
                if (!is_array($spec)) {
                    continue;
                }
                $path = $this->find($dir, $spec['file'] ?? null, []);
                if ($path !== null) {
                    $out['budget'][] = [
                        'path' => $path,
                        'label' => basename($path),
                        'year' => (int) ($spec['year'] ?? 0),
                    ];
                }
            }
        } else {
            foreach ([2026, 2027, 2028] as $year) {
                $path = $this->find($dir, null, [$year . '.csv']);
                if ($path !== null) {
                    $out['budget'][] = ['path' => $path, 'label' => basename($path), 'year' => $year];
                }
            }
        }
        $pairs = [
            'debts' => ['debt snowball.csv'],
            'sinking' => ['sinking funds.csv'],
            'networth' => ['net worth.csv'],
            'credit' => ['credit score.csv'],
            'retirement' => ['investing & retirement.csv', 'investing and retirement.csv'],
            'notes' => ['notes & assumptions.csv', 'notes and assumptions.csv'],
        ];
        $mapKey = [
            'debts' => 'debts',
            'sinking' => 'sinking_funds',
            'networth' => 'net_worth',
            'credit' => 'credit',
            'retirement' => 'retirement',
            'notes' => 'notes',
        ];
        foreach ($pairs as $kind => $names) {
            $forced = $map[$mapKey[$kind]]['file'] ?? null;
            $path = $this->find($dir, is_string($forced) ? $forced : null, $names);
            if ($path !== null) {
                $out[$kind] = [['path' => $path, 'label' => basename($path)]];
            }
        }
        if ($out === []) {
            throw ValidationException::withFields(['dir' => 'No budget CSVs were found in that folder.']);
        }
        return $out;
    }

    /**
     * @param list<string> $names
     */
    private function find(string $dir, ?string $forced, array $names): ?string
    {
        if ($forced !== null && $forced !== '') {
            $path = $dir . '/' . basename($forced);
            if (!is_file($path)) {
                throw ValidationException::withFields(['file' => 'map.json names ' . $forced . ', and that file is not in the folder.']);
            }
            return $path;
        }
        $found = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $found[strtolower($entry)] = $dir . '/' . $entry;
        }
        foreach ($names as $name) {
            if (isset($found[strtolower($name)]) && is_file($found[strtolower($name)])) {
                return $found[strtolower($name)];
            }
        }
        return null;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     * @param array<string, mixed> $map
     * @param array<string, mixed> $counts
     */
    private function transactions(int $userId, array $rows, array $headers, array $map, array &$counts): int
    {
        $forced = is_array($map['transactions']['columns'] ?? null) ? $map['transactions']['columns'] : null;
        $cols = self::matchHeaders($headers, self::TX_ALIASES, ['date', 'account', 'payee'], $forced);
        if (!isset($cols['amount']) && !(isset($cols['debit']) || isset($cols['credit']))) {
            throw ValidationException::withFields([
                'columns' => 'Transactions need an Amount column, or Debit and Credit columns. Headers: ' . implode(', ', $headers) . '.',
            ]);
        }
        $items = [];
        $created = 0;
        $seen = [];
        foreach ($rows as $row) {
            $date = MoneyService::flexDate($row[$cols['date']] ?? '');
            $amount = isset($cols['amount']) ? MoneyService::flexAmount($row[$cols['amount']] ?? '') : $this->signed($row, $cols);
            if ($date === null || $amount === null) {
                continue;
            }
            $account = trim($row[$cols['account']] ?? '');
            if ($account === '') {
                continue;
            }
            $key = mb_strtolower($account);
            $before = $this->money->accountByName($userId, $account);
            if ($before === null && !isset($seen[$key])) {
                $created++;
                $seen[$key] = true;
            }
            $payee = trim($row[$cols['payee']] ?? '');
            $category = isset($cols['category']) ? trim($row[$cols['category']] ?? '') : '';
            $note = isset($cols['note']) ? trim($row[$cols['note']] ?? '') : '';
            $id = isset($cols['id']) ? trim($row[$cols['id']] ?? '') : '';
            $dedupe = $id !== ''
                ? 'sheet:' . mb_substr($id, 0, 140)
                : MoneyService::dedupeKey($date, $amount, $account, $payee);
            $items[] = [
                'date'       => $date,
                'account'    => $account,
                'amount'     => $amount,
                'description'=> $payee,
                'category'   => $category !== '' ? $category : 'Uncategorized',
                'note'       => $note !== '' ? $note : null,
                'dedupe_key' => $dedupe,
            ];
        }
        if ($items === []) {
            throw ValidationException::withFields(['csv' => 'Transactions.csv has headers but no dated amount rows.']);
        }
        $written = 0;
        foreach (array_chunk($items, 200) as $chunk) {
            $result = $this->money->addTransactions($userId, $chunk, 'import');
            $written += (int) $result['written'];
        }
        $counts['accounts'] += $created;
        return $written;
    }

    /**
     * @param array<string, string> $row
     * @param array<string, string> $cols
     */
    private function signed(array $row, array $cols): ?float
    {
        $debit = isset($cols['debit']) ? MoneyService::flexAmount($row[$cols['debit']] ?? '') : null;
        $credit = isset($cols['credit']) ? MoneyService::flexAmount($row[$cols['credit']] ?? '') : null;
        if ($debit !== null && $debit != 0.0) {
            return -abs($debit);
        }
        if ($credit !== null && $credit != 0.0) {
            return abs($credit);
        }
        return null;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     * @param array{year?: int, label: string} $meta
     */
    private function budget(int $userId, array $rows, array $headers, array $meta): int
    {
        $year = (int) ($meta['year'] ?? 0);
        if ($year < 2000) {
            if (preg_match('/(20\d{2})/', $meta['label'], $m) === 1) {
                $year = (int) $m[1];
            }
        }
        $keys = array_map([self::class, 'key'], $headers);
        $monthCols = [];
        foreach (self::MONTHS as $num => $names) {
            foreach ($names as $name) {
                if (in_array($name, $keys, true)) {
                    $monthCols[(int) $num] = $name;
                }
            }
        }
        $written = 0;
        if (count($monthCols) >= 2) {
            $cat = self::matchHeaders($headers, ['category' => ['category', 'name'], 'due_day' => ['due day', 'due', 'due_day']], ['category']);
            foreach ($rows as $row) {
                $name = trim($row[$cat['category']] ?? '');
                if ($name === '') {
                    continue;
                }
                $due = isset($cat['due_day']) ? (int) ($row[$cat['due_day']] ?? 0) : null;
                foreach ($monthCols as $month => $header) {
                    $amount = MoneyService::flexAmount($row[$header] ?? '');
                    if ($amount === null) {
                        continue;
                    }
                    $this->plan->setBudgetLine($userId, $year, $month, $name, abs($amount), $due ?: null);
                    $written++;
                }
            }
            return $written;
        }
        $cols = self::matchHeaders($headers, [
            'category' => ['category', 'name'],
            'month'    => ['month'],
            'amount'   => ['amount', 'planned'],
            'due_day'  => ['due day', 'due'],
        ], ['category', 'month', 'amount']);
        foreach ($rows as $row) {
            $name = trim($row[$cols['category']] ?? '');
            $month = (int) ($row[$cols['month']] ?? 0);
            $amount = MoneyService::flexAmount($row[$cols['amount']] ?? '');
            if ($name === '' || $amount === null || $month < 1) {
                continue;
            }
            $due = isset($cols['due_day']) ? (int) ($row[$cols['due_day']] ?? 0) : null;
            $this->plan->setBudgetLine($userId, $year, $month, $name, abs($amount), $due ?: null);
            $written++;
        }
        return $written;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     */
    private function debts(int $userId, array $rows, array $headers): int
    {
        $cols = self::matchHeaders($headers, [
            'name'    => ['name', 'debt'],
            'balance' => ['balance', 'amount'],
            'apr'     => ['apr', 'rate', 'interest'],
            'minimum' => ['minimum payment', 'minimum', 'payment'],
            'order'   => ['payoff order', 'order'],
            'target'  => ['target payoff date', 'target', 'payoff date'],
            'last4'   => ['last 4', 'last4'],
        ], ['name', 'balance']);
        $n = 0;
        foreach ($rows as $row) {
            $name = trim($row[$cols['name']] ?? '');
            if ($name === '') {
                continue;
            }
            $apr = isset($cols['apr']) ? MoneyService::flexAmount($row[$cols['apr']] ?? '') : 0.0;
            $this->plan->saveDebt($userId, [
                'name'             => $name,
                'balance'          => MoneyService::flexAmount($row[$cols['balance']] ?? '') ?? 0,
                'apr'              => $apr ?? 0,
                'minimum'          => isset($cols['minimum']) ? (MoneyService::flexAmount($row[$cols['minimum']] ?? '') ?? 0) : 0,
                'payoff_order'     => isset($cols['order']) ? (int) ($row[$cols['order']] ?? 1) : 1,
                'target_payoff_on' => isset($cols['target']) ? ($row[$cols['target']] ?? '') : null,
                'last4'            => isset($cols['last4']) ? ($row[$cols['last4']] ?? '') : null,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     */
    private function sinking(int $userId, array $rows, array $headers): int
    {
        $cols = self::matchHeaders($headers, [
            'name'    => ['name', 'fund'],
            'target'  => ['target', 'goal'],
            'balance' => ['balance', 'saved', 'saved so far'],
            'due'     => ['due date', 'due', 'date'],
        ], ['name']);
        $n = 0;
        foreach ($rows as $row) {
            $name = trim($row[$cols['name']] ?? '');
            if ($name === '') {
                continue;
            }
            $this->plan->saveSinkingFund($userId, [
                'name'    => $name,
                'target'  => isset($cols['target']) ? (MoneyService::flexAmount($row[$cols['target']] ?? '') ?? 0) : 0,
                'balance' => isset($cols['balance']) ? (MoneyService::flexAmount($row[$cols['balance']] ?? '') ?? 0) : 0,
                'due_on'  => isset($cols['due']) ? ($row[$cols['due']] ?? '') : null,
            ]);
            $n++;
        }
        return $n;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     */
    private function netWorth(int $userId, array $rows, array $headers): int
    {
        $cols = self::matchHeaders($headers, [
            'date' => ['date', 'as of'],
            'net'  => ['net worth', 'net'],
        ], ['date', 'net']);
        $n = 0;
        foreach ($rows as $row) {
            $date = MoneyService::flexDate($row[$cols['date']] ?? '');
            $net = MoneyService::flexAmount($row[$cols['net']] ?? '');
            if ($date === null || $net === null) {
                continue;
            }
            $breakdown = [];
            foreach ($headers as $header) {
                $key = self::key($header);
                if ($key === $cols['date'] || $key === $cols['net'] || $key === '') {
                    continue;
                }
                $amount = MoneyService::flexAmount($row[$key] ?? '');
                if ($amount !== null) {
                    $breakdown[$header] = $amount;
                }
            }
            $this->money->addSnapshot($userId, $date, $net, $breakdown);
            $n++;
        }
        return $n;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     */
    private function credit(int $userId, array $rows, array $headers): int
    {
        $cols = self::matchHeaders($headers, [
            'date' => ['date', 'as of'],
            'score' => ['score'],
            'utilization' => ['utilization', 'utilization percent'],
        ], ['date', 'score']);
        $n = 0;
        foreach ($rows as $row) {
            $date = MoneyService::flexDate($row[$cols['date']] ?? '');
            $score = (int) ($row[$cols['score']] ?? 0);
            if ($date === null || $score < 300) {
                continue;
            }
            $util = isset($cols['utilization']) ? MoneyService::flexAmount($row[$cols['utilization']] ?? '') : null;
            $this->plan->addCreditScore($userId, $date, $score, $util);
            $n++;
        }
        return $n;
    }

    /**
     * @param list<array<string, string>> $rows
     * @param list<string> $headers
     */
    private function retirement(int $userId, array $rows, array $headers): int
    {
        $cols = self::matchHeaders($headers, [
            'rate'  => ['contribution rate', 'rate', 'contribution'],
            'income'=> ['annual income', 'income'],
            'match' => ['employer match', 'match', 'employer match rate'],
            'step'  => ['baby step', 'step'],
            'note'  => ['note', 'notes'],
        ], ['rate']);
        $row = $rows[0] ?? null;
        if ($row === null) {
            return 0;
        }
        $rate = MoneyService::flexAmount($row[$cols['rate']] ?? '');
        $this->plan->saveRetirement($userId, [
            'contribution_rate'   => $rate,
            'annual_income'       => isset($cols['income']) ? MoneyService::flexAmount($row[$cols['income']] ?? '') : null,
            'employer_match_rate' => isset($cols['match']) ? MoneyService::flexAmount($row[$cols['match']] ?? '') : null,
        ]);
        if (isset($cols['step'])) {
            $step = (int) ($row[$cols['step']] ?? 0);
            if ($step >= 1 && $step <= 7) {
                $note = isset($cols['note']) ? $row[$cols['note']] : null;
                $this->plan->setBabyStep($userId, $step, $note !== '' ? $note : null);
            }
        }
        return 1;
    }

    private function notes(int $userId, string $text): int
    {
        $body = trim($text);
        if ($body === '') {
            return 0;
        }
        if (mb_strlen($body) > PageService::MAX_BODY) {
            $body = mb_substr($body, 0, PageService::MAX_BODY);
        }
        $this->pages->upsertSlug($userId, 'budget-notes', 'Budget notes', $body, 'note', 'system');
        return 1;
    }

    /** @return list<string> */
    private static function headersOf(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $line = strtok(str_replace("\r\n", "\n", $text), "\n");
        if ($line === false) {
            return [];
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return [];
        }
        fwrite($handle, $line . "\n");
        rewind($handle);
        $cells = fgetcsv($handle) ?: [];
        fclose($handle);
        return array_values(array_filter(array_map(static fn ($c): string => trim((string) $c), $cells), static fn (string $c): bool => $c !== ''));
    }

    private static function key(string $header): string
    {
        return strtolower(trim($header));
    }
}
