<?php

declare(strict_types=1);

namespace Dashboard\Invest;

use Dashboard\DB\Database;
use Dashboard\Support\HttpClient;
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
            $market += (float) $position['market_value'];
            $unrealized += (float) $position['unrealized'];
        }
        return [
            'market_value' => $market,
            'unrealized'   => $unrealized,
            'positions'    => $positions,
            'activity'     => $this->activity($userId, 20),
            'wallets'      => $this->wallets($userId),
        ];
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
        return ['positions' => $count];
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
        return array_map(static function (array $row): array {
            $qty = (float) $row['quantity'];
            $avg = (float) $row['avg_cost'];
            $mark = (float) $row['mark'];
            return [
                'symbol'       => (string) $row['symbol'],
                'venue'        => (string) $row['venue'],
                'quantity'     => $qty,
                'avg_cost'     => $avg,
                'mark'         => $mark,
                'market_value' => $mark * $qty,
                'unrealized'   => ($mark - $avg) * $qty,
            ];
        }, $rows);
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
