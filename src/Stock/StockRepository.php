<?php

declare(strict_types=1);

namespace Dashboard\Stock;

use Dashboard\DB\Database;

final class StockRepository
{
    public function __construct(private readonly Database $db) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId, bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM user_stock_symbols WHERE user_id = ?';
        $params = [$userId];

        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }

        $sql .= ' ORDER BY sort_order ASC, symbol ASC';

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllActive(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM user_stock_symbols WHERE is_active = 1 ORDER BY user_id ASC, sort_order ASC'
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByIdForUser(int $id, int $userId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_stock_symbols WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
    }

    public function findBySymbol(int $userId, string $symbol): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM user_stock_symbols WHERE user_id = ? AND symbol = ?',
            [$userId, $symbol]
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_stock_symbols WHERE user_id = ?',
            [$userId]
        );
    }

    public function countPinnedForUser(int $userId): int
    {
        return (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM user_stock_symbols WHERE user_id = ? AND pin_to_dashboard = 1',
            [$userId]
        );
    }

    public function nextSortOrder(int $userId): int
    {
        $max = (int) $this->db->fetchScalar(
            'SELECT COALESCE(MAX(sort_order), -1) FROM user_stock_symbols WHERE user_id = ?',
            [$userId]
        );

        return $max + 1;
    }

    public function insert(int $userId, string $symbol, int $sortOrder, bool $pinToDashboard = false): int
    {
        $this->db->execute(
            'INSERT INTO user_stock_symbols (user_id, symbol, sort_order, pin_to_dashboard) VALUES (?, ?, ?, ?)',
            [$userId, $symbol, $sortOrder, $pinToDashboard ? 1 : 0]
        );

        return (int) $this->db->lastInsertId();
    }

    public function applyQuote(int $id, float $price, float $change, float $changePct, ?string $rawJson): void
    {
        $this->db->execute(
            'UPDATE user_stock_symbols SET
                price = ?, change_amount = ?, change_percent = ?,
                raw_json = ?, last_refreshed = CURRENT_TIMESTAMP
             WHERE id = ?',
            [$price, $change, $changePct, $rawJson, $id]
        );
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(int $id, int $userId, array $fields): bool
    {
        $sets   = [];
        $params = [];

        foreach (['symbol', 'sort_order', 'is_active', 'pin_to_dashboard'] as $key) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[]   = "{$key} = ?";
            $params[] = $fields[$key];
        }

        if ($sets === []) {
            return false;
        }

        $params[] = $id;
        $params[] = $userId;

        return $this->db->execute(
            'UPDATE user_stock_symbols SET ' . implode(', ', $sets) . ' WHERE id = ? AND user_id = ?',
            $params
        ) > 0;
    }

    public function delete(int $id, int $userId): bool
    {
        return $this->db->execute(
            'DELETE FROM user_stock_symbols WHERE id = ? AND user_id = ?',
            [$id, $userId]
        ) > 0;
    }
}
