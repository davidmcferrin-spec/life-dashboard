<?php

declare(strict_types=1);

namespace Dashboard\Shopping;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Shopping lists and items. Adding a name that is already open on the list
 * increases its quantity. Bought rows drop off after the widget's hour window.
 */
final class ShoppingService
{
    private const MAX_LISTS = 20;
    private const MAX_OPEN = 200;
    private const MAX_BATCH = 40;

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function lists(int $userId): array
    {
        $this->ensureDefault($userId);
        $rows = $this->db->fetchAll(
            'SELECT id, name, sort_order, created_at FROM shopping_lists WHERE user_id = ? ORDER BY sort_order, id',
            [$userId]
        );
        $counts = $this->openCounts($userId);
        return array_map(function (array $row) use ($counts): array {
            $id = (int) $row['id'];
            return [
                'id'         => $id,
                'name'       => (string) $row['name'],
                'sort_order' => (int) $row['sort_order'],
                'open_count' => $counts[$id] ?? 0,
                'created_at' => (string) $row['created_at'],
            ];
        }, $rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function createList(int $userId, string $name): array
    {
        $name = $this->listName($name);
        $count = $this->scalar('SELECT COUNT(*) FROM shopping_lists WHERE user_id = ?', [$userId]);
        if ($count >= self::MAX_LISTS) {
            throw ValidationException::withFields(['name' => 'You can keep up to ' . self::MAX_LISTS . ' lists.']);
        }
        $existing = $this->db->fetchOne(
            'SELECT id FROM shopping_lists WHERE user_id = ? AND lower(name) = lower(?)',
            [$userId, $name]
        );
        if ($existing !== null) {
            return $this->listById($userId, (int) $existing['id']);
        }
        $sort = $this->scalar(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM shopping_lists WHERE user_id = ?',
            [$userId]
        );
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO shopping_lists (user_id, name, sort_order) VALUES (?, ?, ?) RETURNING id',
            [$userId, $name, $sort]
        );
        return $this->listById($userId, $id);
    }

    /**
     * @param list<mixed> $rawItems
     * @return list<array<string, mixed>>
     */
    public function addItems(int $userId, int $listId, array $rawItems, string $addedBy, bool $skipIfNameOpen = false): array
    {
        $this->requireList($userId, $listId);
        if ($rawItems === []) {
            throw ValidationException::withFields(['items' => 'Add at least one item.']);
        }
        if (count($rawItems) > self::MAX_BATCH) {
            throw ValidationException::withFields(['items' => 'Add up to ' . self::MAX_BATCH . ' items at a time.']);
        }
        $addedBy = $addedBy === 'agent' ? 'agent' : 'user';
        $out = [];
        foreach ($rawItems as $i => $raw) {
            try {
                $out[] = $this->addOne($userId, $listId, $raw, $addedBy, $skipIfNameOpen);
            } catch (ValidationException $e) {
                $fields = [];
                foreach ($e->fields() as $key => $message) {
                    $fields["items.{$i}.{$key}"] = $message;
                }
                throw ValidationException::withFields($fields !== [] ? $fields : ["items.{$i}" => $e->getMessage()]);
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateItem(int $userId, int $itemId, array $fields): array
    {
        $row = $this->requireItem($userId, $itemId);
        $name = array_key_exists('name', $fields) ? ShoppingText::name((string) $fields['name']) : (string) $row['name'];
        $quantity = array_key_exists('quantity', $fields) ? $this->quantity($fields['quantity']) : (float) $row['quantity'];
        $unit = array_key_exists('unit', $fields) ? ShoppingText::unit($fields['unit'] !== null ? (string) $fields['unit'] : null) : ($row['unit'] !== null ? (string) $row['unit'] : null);
        $category = array_key_exists('category', $fields)
            ? ShoppingText::categoryFor($fields['category'] !== null ? (string) $fields['category'] : null, $name)
            : (string) $row['category'];
        $store = array_key_exists('store', $fields) ? $this->optionalText($fields['store'], 80, 'store') : ($row['store'] !== null ? (string) $row['store'] : null);
        $notes = array_key_exists('notes', $fields) ? $this->optionalText($fields['notes'], 500, 'notes') : ($row['notes'] !== null ? (string) $row['notes'] : null);
        $nameKey = ShoppingText::nameKey($name);
        $unitKey = $unit ?? '';
        $clash = $this->db->fetchOne(
            'SELECT id FROM shopping_items
             WHERE list_id = ? AND bought = 0 AND name_key = ? AND unit_key = ? AND id <> ?',
            [(int) $row['list_id'], $nameKey, $unitKey, $itemId]
        );
        if ($clash !== null) {
            throw ValidationException::withFields(['name' => 'That item is already on the list.']);
        }
        $this->db->execute(
            "UPDATE shopping_items
             SET name = ?, name_key = ?, quantity = ?, unit = ?, unit_key = ?, category = ?, store = ?, notes = ?,
                 updated_at = (now() AT TIME ZONE 'UTC')
             WHERE id = ? AND user_id = ?",
            [$name, $nameKey, $quantity, $unit, $unitKey, $category, $store, $notes, $itemId, $userId]
        );
        return $this->itemById($userId, $itemId);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkItem(int $userId, int $itemId, bool $bought): array
    {
        $this->requireItem($userId, $itemId);
        if ($bought) {
            $now = $this->clock->utcString($this->clock->now());
            $this->db->execute(
                "UPDATE shopping_items
                 SET bought = 1, bought_at = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?",
                [$now, $now, $itemId, $userId]
            );
        } else {
            $row = $this->requireItem($userId, $itemId);
            $clash = $this->db->fetchOne(
                'SELECT id FROM shopping_items
                 WHERE list_id = ? AND bought = 0 AND name_key = ? AND unit_key = ? AND id <> ?',
                [(int) $row['list_id'], $row['name_key'], $row['unit_key'], $itemId]
            );
            if ($clash !== null) {
                $this->db->execute(
                    'UPDATE shopping_items SET quantity = quantity + ? WHERE id = ? AND user_id = ?',
                    [(float) $row['quantity'], (int) $clash['id'], $userId]
                );
                $this->db->execute('DELETE FROM shopping_items WHERE id = ? AND user_id = ?', [$itemId, $userId]);
                return $this->itemById($userId, (int) $clash['id']);
            }
            $this->db->execute(
                "UPDATE shopping_items
                 SET bought = 0, bought_at = NULL, updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE id = ? AND user_id = ?",
                [$itemId, $userId]
            );
        }
        return $this->itemById($userId, $itemId);
    }

    public function removeItem(int $userId, int $itemId): void
    {
        if ($this->db->execute('DELETE FROM shopping_items WHERE id = ? AND user_id = ?', [$itemId, $userId]) === 0) {
            throw new NotFoundException('Item not found.');
        }
    }

    public function clearBought(int $userId, ?int $listId): int
    {
        if ($listId !== null) {
            $this->requireList($userId, $listId);
            return $this->db->execute(
                'DELETE FROM shopping_items WHERE user_id = ? AND list_id = ? AND bought = 1',
                [$userId, $listId]
            );
        }
        return $this->db->execute(
            'DELETE FROM shopping_items WHERE user_id = ? AND bought = 1',
            [$userId]
        );
    }

    /**
     * Drop bought rows older than $hours. Zero keeps them.
     */
    public function pruneBought(int $userId, int $hours, ?int $listId = null): int
    {
        if ($hours <= 0) {
            return 0;
        }
        $hours = min(168, $hours);
        $cutoff = $this->clock->utcString($this->clock->now()->modify("-{$hours} hours"));
        $sql = 'DELETE FROM shopping_items WHERE user_id = ? AND bought = 1 AND bought_at IS NOT NULL AND bought_at < ?';
        $params = [$userId, $cutoff];
        if ($listId !== null) {
            $sql .= ' AND list_id = ?';
            $params[] = $listId;
        }
        return $this->db->execute($sql, $params);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function items(int $userId, ?int $listId, bool $includeBought, int $clearHours): array
    {
        $this->pruneBought($userId, $clearHours, $listId);
        if ($listId !== null) {
            $this->requireList($userId, $listId);
        }
        $sql = 'SELECT * FROM shopping_items WHERE user_id = ?';
        $params = [$userId];
        if ($listId !== null) {
            $sql .= ' AND list_id = ?';
            $params[] = $listId;
        }
        if (!$includeBought) {
            $sql .= ' AND bought = 0';
        }
        $sql .= ' ORDER BY bought, category, name_key, id';
        return array_map(fn (array $row): array => $this->present($row), $this->db->fetchAll($sql, $params));
    }

    /**
     * Widget payload: lists, open items grouped by aisle, bought items separate.
     *
     * @return array<string, mixed>
     */
    public function board(int $userId, int $listId, int $clearHours): array
    {
        $this->pruneBought($userId, $clearHours, $listId > 0 ? $listId : null);
        $lists = $this->lists($userId);
        if ($listId > 0) {
            $this->requireList($userId, $listId);
            $lists = array_values(array_filter($lists, static fn (array $list): bool => $list['id'] === $listId));
        }
        $items = $this->items($userId, $listId > 0 ? $listId : null, true, 0);
        $byList = [];
        foreach ($items as $item) {
            $byList[$item['list_id']][] = $item;
        }
        $out = [];
        foreach ($lists as $list) {
            $rows = $byList[$list['id']] ?? [];
            $open = array_values(array_filter($rows, static fn (array $item): bool => !$item['bought']));
            $bought = array_values(array_filter($rows, static fn (array $item): bool => $item['bought']));
            $out[] = [
                'id'         => $list['id'],
                'name'       => $list['name'],
                'open_count' => count($open),
                'groups'     => $this->group($open),
                'bought'     => $bought,
            ];
        }
        return [
            'metric_ids'  => [],
            'clear_hours' => $clearHours,
            'lists'       => $out,
        ];
    }

    /**
     * @param array<string, mixed>|string $raw
     * @return array<string, mixed>
     */
    private function addOne(int $userId, int $listId, mixed $raw, string $addedBy, bool $skipIfNameOpen = false): array
    {
        $parsed = $this->normalizeInput($raw);
        if ($skipIfNameOpen) {
            $named = $this->db->fetchOne(
                'SELECT id FROM shopping_items WHERE list_id = ? AND bought = 0 AND name_key = ?',
                [$listId, $parsed['name_key']]
            );
            if ($named !== null) {
                $item = $this->itemById($userId, (int) $named['id']);
                $item['merged'] = false;
                $item['skipped'] = true;
                return $item;
            }
        }
        $open = $this->scalar(
            'SELECT COUNT(*) FROM shopping_items WHERE list_id = ? AND bought = 0',
            [$listId]
        );
        $existing = $this->db->fetchOne(
            'SELECT id, quantity, store, notes FROM shopping_items
             WHERE list_id = ? AND bought = 0 AND name_key = ? AND unit_key = ?',
            [$listId, $parsed['name_key'], $parsed['unit_key']]
        );
        if ($existing !== null) {
            $store = $existing['store'] !== null && $existing['store'] !== ''
                ? (string) $existing['store']
                : $parsed['store'];
            $notes = $existing['notes'] !== null && $existing['notes'] !== ''
                ? (string) $existing['notes']
                : $parsed['notes'];
            $this->db->execute(
                "UPDATE shopping_items
                 SET quantity = quantity + ?, store = ?, notes = ?, updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE id = ? AND user_id = ?",
                [$parsed['quantity'], $store, $notes, (int) $existing['id'], $userId]
            );
            $item = $this->itemById($userId, (int) $existing['id']);
            $item['merged'] = true;
            return $item;
        }
        if ($open >= self::MAX_OPEN) {
            throw ValidationException::withFields(['name' => 'This list is full.']);
        }
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO shopping_items
                (list_id, user_id, name, name_key, quantity, unit, unit_key, store, category, notes, added_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $listId, $userId, $parsed['name'], $parsed['name_key'], $parsed['quantity'],
                $parsed['unit'], $parsed['unit_key'], $parsed['store'], $parsed['category'],
                $parsed['notes'], $addedBy,
            ]
        );
        $item = $this->itemById($userId, $id);
        $item['merged'] = false;
        return $item;
    }

    /**
     * @param array<string, mixed>|string $raw
     * @return array{name: string, name_key: string, quantity: float, unit: ?string, unit_key: string, category: string, store: ?string, notes: ?string}
     */
    private function normalizeInput(mixed $raw): array
    {
        if (is_string($raw)) {
            $parsed = ShoppingText::parse($raw);
            $parsed['store'] = null;
            $parsed['notes'] = null;
            return $parsed;
        }
        if (!is_array($raw)) {
            throw ValidationException::withFields(['item' => 'Invalid item.']);
        }
        if (isset($raw['text']) && is_string($raw['text']) && trim($raw['text']) !== '') {
            $parsed = ShoppingText::parse($raw['text']);
        } else {
            $name = ShoppingText::name((string) ($raw['name'] ?? ''));
            $unit = ShoppingText::unit(isset($raw['unit']) ? (string) $raw['unit'] : null);
            $parsed = [
                'name'     => $name,
                'name_key' => ShoppingText::nameKey($name),
                'quantity' => array_key_exists('quantity', $raw) ? $this->quantity($raw['quantity']) : 1.0,
                'unit'     => $unit,
                'unit_key' => $unit ?? '',
                'category' => ShoppingText::category($name),
            ];
        }
        if (array_key_exists('quantity', $raw) && !isset($raw['text'])) {
            $parsed['quantity'] = $this->quantity($raw['quantity']);
        }
        $parsed['category'] = ShoppingText::categoryFor(
            isset($raw['category']) ? (string) $raw['category'] : null,
            $parsed['name']
        );
        $parsed['store'] = $this->optionalText($raw['store'] ?? null, 80, 'store');
        $parsed['notes'] = $this->optionalText($raw['notes'] ?? null, 500, 'notes');
        return $parsed;
    }

    private function ensureDefault(int $userId): void
    {
        $n = $this->scalar('SELECT COUNT(*) FROM shopping_lists WHERE user_id = ?', [$userId]);
        if ($n === 0) {
            $this->db->fetchScalar(
                'INSERT INTO shopping_lists (user_id, name, sort_order) VALUES (?, ?, 0) RETURNING id',
                [$userId, 'Groceries']
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function openCounts(int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT list_id, COUNT(*) AS n FROM shopping_items WHERE user_id = ? AND bought = 0 GROUP BY list_id',
            [$userId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['list_id']] = (int) $row['n'];
        }
        return $out;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array{category: string, items: list<array<string, mixed>>}>
     */
    private function group(array $items): array
    {
        $buckets = [];
        foreach ($items as $item) {
            $buckets[$item['category']][] = $item;
        }
        $groups = [];
        foreach (ShoppingText::CATEGORIES as $category) {
            if (!empty($buckets[$category])) {
                $groups[] = ['category' => $category, 'items' => $buckets[$category]];
            }
        }
        return $groups;
    }

    /**
     * @return array<string, mixed>
     */
    private function listById(int $userId, int $id): array
    {
        foreach ($this->lists($userId) as $list) {
            if ($list['id'] === $id) {
                return $list;
            }
        }
        throw new NotFoundException('List not found.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireList(int $userId, int $listId): array
    {
        $row = $this->db->fetchOne('SELECT id, name FROM shopping_lists WHERE id = ? AND user_id = ?', [$listId, $userId]);
        if ($row === null) {
            throw new NotFoundException('List not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireItem(int $userId, int $itemId): array
    {
        $row = $this->db->fetchOne('SELECT * FROM shopping_items WHERE id = ? AND user_id = ?', [$itemId, $userId]);
        if ($row === null) {
            throw new NotFoundException('Item not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function itemById(int $userId, int $itemId): array
    {
        return $this->present($this->requireItem($userId, $itemId));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        $quantity = (float) $row['quantity'];
        return [
            'id'        => (int) $row['id'],
            'list_id'   => (int) $row['list_id'],
            'name'      => (string) $row['name'],
            'quantity'  => $quantity,
            'unit'      => $row['unit'] !== null && $row['unit'] !== '' ? (string) $row['unit'] : null,
            'store'     => $row['store'] !== null && $row['store'] !== '' ? (string) $row['store'] : null,
            'category'  => (string) $row['category'],
            'notes'     => $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : null,
            'added_by'  => (string) $row['added_by'],
            'bought'    => (int) $row['bought'] === 1,
            'bought_at' => $row['bought_at'] !== null ? (string) $row['bought_at'] : null,
        ];
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params): int
    {
        $row = $this->db->fetchScalar($sql, $params);
        return (int) ($row ?? 0);
    }

    private function listName(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if ($name === '' || mb_strlen($name) > 80) {
            throw ValidationException::withFields(['name' => 'List name must be 1–80 characters.']);
        }
        return $name;
    }

    private function quantity(mixed $value): float
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields(['quantity' => 'Quantity must be a number.']);
        }
        $n = round((float) $value, 3);
        if ($n <= 0 || $n > 9999) {
            throw ValidationException::withFields(['quantity' => 'Quantity must be greater than 0.']);
        }
        return $n;
    }

    private function optionalText(mixed $value, int $max, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            throw ValidationException::withFields([$field => ucfirst($field) . " must be {$max} characters or fewer."]);
        }
        return $text;
    }
}
