<?php

declare(strict_types=1);

namespace Dashboard\Invest;

use Dashboard\DB\Database;
use Dashboard\Money\AccountNumberGuard;
use Dashboard\Support\HttpClient;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Read-only portfolio. CSV import and a public Polymarket refresh.
 * There is no method that places, cancels, or signs an order.
 */
final class InvestService
{
    public function __construct(
        private readonly Database $db,
        private readonly ?HttpClient $http = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function scorecard(int $userId): array
    {
        $positions = $this->positions($userId);
        $market = 0.0;
        $unrealized = 0.0;
        foreach ($positions as $position) {
            // A missing price adds nothing. It is not turned into a loss.
            if ($position['market_value'] !== null) {
                $market += (float) $position['market_value'];
            }
            if ($position['unrealized'] !== null) {
                $unrealized += (float) $position['unrealized'];
            }
        }
        $observed = $this->observe($userId, $positions);
        return [
            'market_value'     => $market,
            'portfolio_value'  => $observed['portfolio_value'],
            'unrealized'       => $unrealized,
            'day_change'       => $observed['day_change'],
            'day_change_ratio' => $observed['day_change_ratio'],
            'buying_power'     => $observed['buying_power'],
            'annual_return'    => $observed['annual_return'],
            'series'           => $observed['series'],
            'as_of'            => $observed['as_of'],
            'positions'        => $positions,
            'activity'         => $this->activity($userId, 20),
            'wallets'          => $this->wallets($userId),
        ];
    }

    /**
     * Manual holding only. No broker and no order.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveManualPosition(int $userId, array $input): array
    {
        $symbol = $this->cleanSymbol($input['symbol'] ?? '');
        $name = $this->cleanHoldingName($input['name'] ?? null);
        $quantity = $this->cleanQuantity($input['quantity'] ?? null);
        $costSet = $this->hasNumber($input, 'avg_cost');
        $priceSet = $this->hasNumber($input, 'mark');
        $avg = $costSet ? $this->cleanMoney($input['avg_cost'], 'avg_cost') : 0.0;
        $mark = $priceSet ? $this->cleanMoney($input['mark'], 'mark') : 0.0;
        $meta = json_encode([
            'source'    => 'manual',
            'name'      => $name,
            'price_set' => $priceSet,
            'cost_set'  => $costSet,
        ], JSON_THROW_ON_ERROR);
        $id = isset($input['id']) ? (int) $input['id'] : 0;

        if ($id > 0) {
            $existing = $this->db->fetchOne(
                "SELECT id FROM invest_positions WHERE id = ? AND user_id = ? AND venue = 'manual'",
                [$id, $userId]
            );
            if ($existing === null) {
                throw new NotFoundException('Position not found.');
            }
            $clash = $this->db->fetchOne(
                "SELECT id FROM invest_positions WHERE user_id = ? AND venue = 'manual' AND symbol = ? AND id <> ?",
                [$userId, $symbol, $id]
            );
            if ($clash !== null) {
                throw ValidationException::withFields(['symbol' => 'That symbol is already a manual holding.']);
            }
            $this->db->execute(
                "UPDATE invest_positions
                 SET symbol = ?, quantity = ?, avg_cost = ?, mark = ?, meta = ?::jsonb,
                     updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE id = ? AND user_id = ? AND venue = 'manual'",
                [$symbol, $quantity, $avg, $mark, $meta, $id, $userId]
            );
        } else {
            $this->db->execute(
                "INSERT INTO invest_positions (user_id, venue, symbol, quantity, avg_cost, mark, meta)
                 VALUES (?, 'manual', ?, ?, ?, ?, ?::jsonb)
                 ON CONFLICT (user_id, venue, symbol) DO UPDATE
                    SET quantity = EXCLUDED.quantity,
                        avg_cost = EXCLUDED.avg_cost,
                        mark = EXCLUDED.mark,
                        meta = EXCLUDED.meta,
                        updated_at = (now() AT TIME ZONE 'UTC')",
                [$userId, $symbol, $quantity, $avg, $mark, $meta]
            );
        }

        $this->observe($userId, $this->positions($userId));
        $saved = $this->db->fetchOne(
            "SELECT * FROM invest_positions WHERE user_id = ? AND venue = 'manual' AND symbol = ?",
            [$userId, $symbol]
        );
        if ($saved === null) {
            throw new NotFoundException('Position not found.');
        }
        return $this->formatPosition($saved);
    }

    public function deleteManualPosition(int $userId, int $id): void
    {
        $deleted = $this->db->execute(
            "DELETE FROM invest_positions WHERE id = ? AND user_id = ? AND venue = 'manual'",
            [$id, $userId]
        );
        if ($deleted < 1) {
            throw new NotFoundException('Position not found.');
        }
        $this->observe($userId, $this->positions($userId));
    }

    /**
     * Records buying power for today. Null clears it. This is not a trade.
     *
     * @return array{as_of: string, cash: float|null}
     */
    public function saveCash(int $userId, mixed $cash): array
    {
        $asOf = gmdate('Y-m-d');
        if ($cash === null || (is_string($cash) && trim($cash) === '')) {
            $this->db->execute(
                'UPDATE invest_value_history SET cash = NULL WHERE user_id = ? AND as_of = ?',
                [$userId, $asOf]
            );
            return ['as_of' => $asOf, 'cash' => null];
        }
        $amount = $this->cleanMoney($cash, 'cash');
        $this->db->execute(
            'INSERT INTO invest_value_history (user_id, as_of, cash) VALUES (?, ?, ?)
             ON CONFLICT (user_id, as_of) DO UPDATE SET cash = EXCLUDED.cash',
            [$userId, $asOf, $amount]
        );
        return ['as_of' => $asOf, 'cash' => $amount];
    }

    /**
     * @return array{imported: int, positions: int}
     */
    public function importRobinhood(int $userId, string $csv): array
    {
        $rows = RobinhoodCsv::rows($csv);
        if ($rows === []) {
            throw ValidationException::withFields(['csv' => 'No activity rows found. Expected a Robinhood activity export.']);
        }
        $imported = 0;
        $this->db->transaction(function (Database $db) use ($userId, $rows, &$imported): void {
            foreach ($rows as $row) {
                if ($row['amount'] === null && $row['price'] !== null && $row['quantity']) {
                    $sign = RobinhoodCsv::isBuy($row['kind']) ? -1 : 1;
                    $row['amount'] = $sign * $row['price'] * $row['quantity'];
                }
                $db->execute(
                    'INSERT INTO invest_activity (user_id, venue, symbol, kind, quantity, amount, occurred_on, note, dedupe_key)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                     ON CONFLICT (user_id, dedupe_key) DO NOTHING',
                    [
                        $userId, 'robinhood', $row['symbol'], $row['kind'], $row['quantity'],
                        $row['amount'], $row['occurred_on'], $row['note'], $row['dedupe_key'],
                    ]
                );
                $imported++;
            }
        });
        $positions = $this->rebuildRobinhood($userId);
        $this->observe($userId, $this->positions($userId));
        return ['imported' => $imported, 'positions' => $positions];
    }

    /**
     * @return array<string, mixed>
     */
    public function saveWallet(int $userId, string $address): array
    {
        $address = trim($address);
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            throw ValidationException::withFields(['address' => 'Enter a 0x Polymarket wallet address.']);
        }
        $this->db->execute(
            "INSERT INTO invest_wallets (user_id, venue, address) VALUES (?, 'polymarket', ?)
             ON CONFLICT (user_id, venue) DO UPDATE
                SET address = EXCLUDED.address, updated_at = (now() AT TIME ZONE 'UTC')",
            [$userId, $address]
        );
        return ['venue' => 'polymarket', 'address' => $address];
    }

    /**
     * Pull public positions for the saved wallet. No order is sent.
     *
     * @return array{positions: int}
     */
    public function refreshPolymarket(int $userId): array
    {
        $wallet = $this->db->fetchOne(
            "SELECT address FROM invest_wallets WHERE user_id = ? AND venue = 'polymarket'",
            [$userId]
        );
        if ($wallet === null) {
            throw ValidationException::withFields(['address' => 'Save a Polymarket wallet first.']);
        }
        $address = (string) $wallet['address'];
        if (!preg_match('/^0x[a-fA-F0-9]{40}$/', $address)) {
            throw ValidationException::withFields(['address' => 'Saved wallet is not a 0x address.']);
        }
        $url = 'https://data-api.polymarket.com/positions?user=' . rawurlencode($address);
        $http = $this->http ?? new HttpClient();
        try {
            $payload = $http->requestJson('GET', $url, null, ['Accept' => 'application/json'], 20);
        } catch (\RuntimeException $e) {
            throw new ValidationException('Polymarket data API is unreachable.');
        }
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new ValidationException('Polymarket returned an unexpected payload.');
        }

        $count = 0;
        $this->db->transaction(function (Database $db) use ($userId, $payload, &$count): void {
            $db->execute("DELETE FROM invest_positions WHERE user_id = ? AND venue = 'polymarket'", [$userId]);
            foreach ($payload as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $title = trim((string) ($row['title'] ?? $row['slug'] ?? 'market'));
                $outcome = trim((string) ($row['outcome'] ?? ''));
                $symbol = mb_substr($outcome !== '' ? $title . ' · ' . $outcome : $title, 0, 80);
                $qty = (float) ($row['size'] ?? 0);
                if ($qty == 0.0 || $symbol === '') {
                    continue;
                }
                $avg = (float) ($row['avgPrice'] ?? 0);
                $mark = (float) ($row['curPrice'] ?? $avg);
                $db->execute(
                    "INSERT INTO invest_positions (user_id, venue, symbol, quantity, avg_cost, mark, meta)
                     VALUES (?, 'polymarket', ?, ?, ?, ?, ?::jsonb)",
                    [$userId, $symbol, $qty, $avg, $mark, json_encode(['source' => 'data-api'], JSON_THROW_ON_ERROR)]
                );
                $count++;
            }
        });
        $this->observe($userId, $this->positions($userId));
        return ['positions' => $count];
    }

    /**
     * @param list<array<string, mixed>> $positions
     * @return array<string, mixed>
     */
    private function observe(int $userId, array $positions): array
    {
        // Today's total is stored when it is known. Earlier days are never filled in.
        $portfolio = ValueHistory::portfolioValue($positions);
        $asOf = gmdate('Y-m-d');
        $this->db->execute(
            'INSERT INTO invest_value_history (user_id, as_of, market_value) VALUES (?, ?, ?)
             ON CONFLICT (user_id, as_of) DO UPDATE SET market_value = EXCLUDED.market_value',
            [$userId, $asOf, $portfolio]
        );
        $metrics = ValueHistory::metrics($this->history($userId), $asOf);
        $metrics['as_of'] = $asOf;
        $metrics['portfolio_value'] = $portfolio;
        return $metrics;
    }

    /**
     * @return list<array{as_of: string, market_value: float|null, cash: float|null}>
     */
    private function history(int $userId): array
    {
        return array_map(static function (array $row): array {
            return [
                'as_of'        => substr((string) $row['as_of'], 0, 10),
                'market_value' => $row['market_value'] !== null ? (float) $row['market_value'] : null,
                'cash'         => $row['cash'] !== null ? (float) $row['cash'] : null,
            ];
        }, $this->db->fetchAll(
            'SELECT as_of, market_value, cash FROM invest_value_history WHERE user_id = ? ORDER BY as_of ASC',
            [$userId]
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function positions(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM invest_positions WHERE user_id = ? AND quantity <> 0 ORDER BY venue ASC, symbol ASC',
            [$userId]
        );
        return array_map(fn (array $row): array => $this->formatPosition($row), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatPosition(array $row): array
    {
        $meta = $this->meta($row['meta'] ?? null);
        $qty = (float) $row['quantity'];
        $avg = (float) $row['avg_cost'];
        $mark = (float) $row['mark'];
        $priceKnown = !array_key_exists('price_set', $meta) || $meta['price_set'] === true;
        $costKnown = !array_key_exists('cost_set', $meta) || $meta['cost_set'] === true;
        $name = isset($meta['name']) && is_string($meta['name']) && $meta['name'] !== ''
            ? $meta['name']
            : (string) $row['symbol'];
        $knownMark = $priceKnown ? $mark : null;
        $knownAvg = $costKnown ? $avg : null;
        return [
            'id'           => (int) $row['id'],
            'symbol'       => (string) $row['symbol'],
            'name'         => $name,
            'venue'        => (string) $row['venue'],
            'quantity'     => $qty,
            'avg_cost'     => $knownAvg,
            'mark'         => $knownMark,
            'market_value' => ValueHistory::positionValue($knownMark, $qty, $priceKnown),
            'unrealized'   => ValueHistory::unrealized($knownMark, $knownAvg, $qty, $priceKnown, $costKnown),
            'return_ratio' => ValueHistory::positionReturn($knownMark, $knownAvg, $qty, $priceKnown, $costKnown),
            'manual'       => (string) $row['venue'] === 'manual',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function meta(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function cleanSymbol(mixed $value): string
    {
        $symbol = strtoupper(trim((string) $value));
        if ($symbol === '' || preg_match('/^[A-Z0-9][A-Z0-9.\-]{0,15}$/', $symbol) !== 1) {
            throw ValidationException::withFields(['symbol' => 'Enter a symbol of up to 16 letters, digits, dots, or hyphens.']);
        }
        AccountNumberGuard::assertNoFullNumber($symbol, 'symbol');
        return $symbol;
    }

    private function cleanHoldingName(mixed $value): ?string
    {
        $name = trim((string) $value);
        if ($name === '') {
            return null;
        }
        if (mb_strlen($name) > 80) {
            throw ValidationException::withFields(['name' => 'Name must be 80 characters or fewer.']);
        }
        AccountNumberGuard::assertNoFullNumber($name, 'name');
        return $name;
    }

    private function cleanQuantity(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields(['quantity' => 'Enter the number of shares.']);
        }
        $quantity = (float) $value;
        if ($quantity <= 0 || $quantity > 1000000000) {
            throw ValidationException::withFields(['quantity' => 'Shares must be greater than zero.']);
        }
        return $quantity;
    }

    private function cleanMoney(mixed $value, string $field): float
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if (!is_numeric($value)) {
            throw ValidationException::withFields([$field => 'Enter a number.']);
        }
        $amount = (float) $value;
        if ($amount < 0 || $amount > 1000000000000) {
            throw ValidationException::withFields([$field => 'Amount must be zero or greater.']);
        }
        return $amount;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function hasNumber(array $input, string $key): bool
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return false;
        }
        return !(is_string($input[$key]) && trim($input[$key]) === '');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function activity(int $userId, int $limit): array
    {
        return array_map(static fn (array $row): array => [
            'date'   => (string) $row['occurred_on'],
            'venue'  => (string) $row['venue'],
            'symbol' => $row['symbol'] !== null ? (string) $row['symbol'] : null,
            'kind'   => (string) $row['kind'],
            'amount' => $row['amount'] !== null ? (float) $row['amount'] : null,
        ], $this->db->fetchAll(
            "SELECT occurred_on, venue, symbol, kind, amount FROM invest_activity
             WHERE user_id = ? ORDER BY occurred_on DESC, id DESC LIMIT {$limit}",
            [$userId]
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wallets(int $userId): array
    {
        return array_map(static fn (array $row): array => [
            'venue'   => (string) $row['venue'],
            'address' => (string) $row['address'],
        ], $this->db->fetchAll('SELECT venue, address FROM invest_wallets WHERE user_id = ?', [$userId]));
    }

    private function rebuildRobinhood(int $userId): int
    {
        $rows = $this->db->fetchAll(
            "SELECT symbol, kind, quantity, amount, occurred_on FROM invest_activity
             WHERE user_id = ? AND venue = 'robinhood' AND symbol IS NOT NULL
             ORDER BY occurred_on ASC, id ASC",
            [$userId]
        );
        /** @var array<string, array{qty: float, cost: float, mark: float}> $book */
        $book = [];
        foreach ($rows as $row) {
            $symbol = (string) $row['symbol'];
            $qty = (float) ($row['quantity'] ?? 0);
            $amount = $row['amount'] !== null ? abs((float) $row['amount']) : 0.0;
            $price = $qty > 0 ? $amount / $qty : 0.0;
            $slot = $book[$symbol] ?? ['qty' => 0.0, 'cost' => 0.0, 'mark' => 0.0];
            $kind = (string) $row['kind'];
            if (RobinhoodCsv::isBuy($kind) && $qty > 0) {
                $slot['cost'] = (($slot['qty'] * $slot['cost']) + ($qty * $price)) / ($slot['qty'] + $qty);
                $slot['qty'] += $qty;
                $slot['mark'] = $price;
            } elseif (RobinhoodCsv::isSell($kind) && $qty > 0) {
                $slot['qty'] = max(0.0, $slot['qty'] - $qty);
                if ($price > 0) {
                    $slot['mark'] = $price;
                }
            }
            $book[$symbol] = $slot;
        }

        $this->db->execute("DELETE FROM invest_positions WHERE user_id = ? AND venue = 'robinhood'", [$userId]);
        $n = 0;
        foreach ($book as $symbol => $slot) {
            if ($slot['qty'] <= 0.0000001) {
                continue;
            }
            $this->db->execute(
                "INSERT INTO invest_positions (user_id, venue, symbol, quantity, avg_cost, mark)
                 VALUES (?, 'robinhood', ?, ?, ?, ?)",
                [$userId, $symbol, $slot['qty'], $slot['cost'], $slot['mark']]
            );
            $n++;
        }
        return $n;
    }
}
