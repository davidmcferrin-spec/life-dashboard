<?php

declare(strict_types=1);

namespace Dashboard\Shopping;

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Shopping lists and items. An open row merges when the name and unit match,
 * or when dedupe_key matches, so a near-duplicate updates that row.
 * Bought rows drop off after the widget's hour window.
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
        $source = array_key_exists('source', $fields) ? $this->bounded($fields['source'], 64, 'source') : $this->blankToNull($row['source'] ?? null);
        $dedupe = array_key_exists('dedupe_key', $fields) ? $this->bounded($fields['dedupe_key'], 160, 'dedupe_key') : $this->blankToNull($row['dedupe_key'] ?? null);
        $needed = array_key_exists('needed_by', $fields) ? $this->planDate($fields['needed_by']) : $this->blankToNull($row['needed_by'] ?? null);
        $wave = array_key_exists('order_wave', $fields) ? $this->bounded($fields['order_wave'], 80, 'order_wave', true) : $this->blankToNull($row['order_wave'] ?? null);
        $tripId = array_key_exists('trip_id', $fields)
            ? $this->ownedTrip($userId, $this->tripId($fields['trip_id']))
            : ($row['trip_id'] !== null && $row['trip_id'] !== '' ? (int) $row['trip_id'] : null);
        $nameKey = ShoppingText::nameKey($name);
        $unitKey = $unit ?? '';
        if ((int) $row['bought'] === 0) {
            $this->assertOpenIdentity((int) $row['list_id'], $itemId, $nameKey, $unitKey, $dedupe);
        }
        $this->db->execute(
            "UPDATE shopping_items
             SET name = ?, name_key = ?, quantity = ?, unit = ?, unit_key = ?, category = ?, store = ?, notes = ?,
                 source = ?, dedupe_key = ?, needed_by = ?, order_wave = ?, trip_id = ?,
                 updated_at = (now() AT TIME ZONE 'UTC')
             WHERE id = ? AND user_id = ?",
            [$name, $nameKey, $quantity, $unit, $unitKey, $category, $store, $notes, $source, $dedupe, $needed, $wave, $tripId, $itemId, $userId]
        );
        return $this->itemById($userId, $itemId);
    }

    /**
     * @return array<string, mixed>
     */
    public function checkItem(int $userId, int $itemId, bool $bought): array
    {
        $row = $this->requireItem($userId, $itemId);
        if ($bought) {
            $now = $this->clock->utcString($this->clock->now());
            $this->db->execute(
                "UPDATE shopping_items
                 SET bought = 1, bought_at = ?, updated_at = ?
                 WHERE id = ? AND user_id = ?",
                [$now, $now, $itemId, $userId]
            );
            return $this->itemById($userId, $itemId);
        }
        return $this->db->transaction(function () use ($userId, $itemId, $row): array {
            $returningKey = $this->blankToNull($row['dedupe_key'] ?? null);
            $conflicts = $this->openConflicts(
                (int) $row['list_id'],
                $itemId,
                (string) $row['name_key'],
                (string) $row['unit_key'],
                $returningKey
            );
            if ($conflicts === []) {
                $this->db->execute(
                    "UPDATE shopping_items
                     SET bought = 0, bought_at = NULL, updated_at = (now() AT TIME ZONE 'UTC')
                     WHERE id = ? AND user_id = ?",
                    [$itemId, $userId]
                );
                return $this->itemById($userId, $itemId);
            }
            $survivor = $conflicts[0];
            foreach ($conflicts as $candidate) {
                if ($returningKey !== null && $this->blankToNull($candidate['dedupe_key'] ?? null) === $returningKey) {
                    $survivor = $candidate;
                    break;
                }
            }
            if (!$this->keysCompatible($this->blankToNull($survivor['dedupe_key'] ?? null), $returningKey)) {
                throw ValidationException::withFields(['name' => 'That item is already on the list.']);
            }
            $quantity = (float) $survivor['quantity'] + (float) $row['quantity'];
            $survivor = $this->fillBlanks($survivor, $row);
            foreach ($conflicts as $other) {
                if ((int) $other['id'] === (int) $survivor['id']) {
                    continue;
                }
                if (!$this->keysCompatible($this->blankToNull($survivor['dedupe_key'] ?? null), $this->blankToNull($other['dedupe_key'] ?? null))) {
                    throw ValidationException::withFields(['name' => 'That item is already on the list.']);
                }
                $quantity += (float) $other['quantity'];
                $survivor = $this->fillBlanks($survivor, $other);
                $this->db->execute('DELETE FROM shopping_items WHERE id = ? AND user_id = ?', [(int) $other['id'], $userId]);
            }
            if ($quantity > 9999) {
                throw ValidationException::withFields(['quantity' => 'Quantity must be greater than 0.']);
            }
            $this->db->execute('DELETE FROM shopping_items WHERE id = ? AND user_id = ?', [$itemId, $userId]);
            $this->db->execute(
                "UPDATE shopping_items
                 SET quantity = ?, store = ?, notes = ?, source = ?, dedupe_key = ?, needed_by = ?, order_wave = ?,
                     updated_at = (now() AT TIME ZONE 'UTC')
                 WHERE id = ? AND user_id = ?",
                [
                    round($quantity, 3),
                    $this->blankToNull($survivor['store'] ?? null),
                    $this->blankToNull($survivor['notes'] ?? null),
                    $this->blankToNull($survivor['source'] ?? null),
                    $this->blankToNull($survivor['dedupe_key'] ?? null),
                    $this->blankToNull($survivor['needed_by'] ?? null),
                    $this->blankToNull($survivor['order_wave'] ?? null),
                    (int) $survivor['id'],
                    $userId,
                ]
            );
            return $this->itemById($userId, (int) $survivor['id']);
        });
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
     * include_deferred defaults to true so the widget can split that wave out of the cart.
     *
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function items(int $userId, ?int $listId, bool $includeBought, int $clearHours, array $filters = []): array
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
        $wave = isset($filters['order_wave']) ? trim((string) $filters['order_wave']) : '';
        if ($wave !== '') {
            if (mb_strlen($wave) > 80) {
                throw ValidationException::withFields(['order_wave' => 'order_wave must be 80 characters or fewer.']);
            }
            $sql .= ' AND lower(btrim(order_wave)) = lower(?)';
            $params[] = $wave;
        }
        $includeDeferred = !array_key_exists('include_deferred', $filters) || filter_var($filters['include_deferred'], FILTER_VALIDATE_BOOLEAN);
        if (!$includeDeferred && strcasecmp($wave, 'deferred') !== 0) {
            $sql .= " AND (bought = 1 OR order_wave IS NULL OR lower(btrim(order_wave)) <> 'deferred')";
        }
        foreach ([
            'needed_by' => 'needed_by = ?',
            'needed_before' => 'needed_by IS NOT NULL AND needed_by <= ?',
            'needed_after' => 'needed_by IS NOT NULL AND needed_by >= ?',
        ] as $key => $clause) {
            if (!array_key_exists($key, $filters) || $filters[$key] === null || $filters[$key] === '') {
                continue;
            }
            try {
                $date = Clock::normalizeDate(trim((string) $filters[$key]));
            } catch (\InvalidArgumentException) {
                throw ValidationException::withFields([$key => 'Date must be YYYY-MM-DD.']);
            }
            $sql .= ' AND ' . $clause;
            $params[] = $date;
        }
        $sql .= " ORDER BY bought,
            CASE WHEN bought = 0 AND lower(btrim(COALESCE(order_wave, ''))) = 'deferred' THEN 1 ELSE 0 END,
            category, name_key, id";
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
            $cart = [];
            $deferred = [];
            foreach ($open as $item) {
                if (strcasecmp((string) ($item['order_wave'] ?? ''), 'deferred') === 0) {
                    $deferred[] = $item;
                } else {
                    $cart[] = $item;
                }
            }
            $bought = array_values(array_filter($rows, static fn (array $item): bool => $item['bought']));
            $out[] = [
                'id'         => $list['id'],
                'name'       => $list['name'],
                'open_count' => count($cart),
                'groups'     => $this->group($cart),
                'deferred'   => $deferred,
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
     * A repeated dedupe_key updates that open row and adds the quantity.
     * The latest name and unit replace the near-duplicate. Notes already on the row stay.
     *
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
        return $this->mergeOpen($userId, $listId, $parsed, $addedBy);
    }

    /**
     * @param array{name: string, name_key: string, quantity: float, unit: ?string, unit_key: string, category: string, store: ?string, notes: ?string, source: ?string, dedupe_key: ?string, needed_by: ?string, order_wave: ?string} $parsed
     * @return array<string, mixed>
     */
    private function mergeOpen(int $userId, int $listId, array $parsed, string $addedBy): array
    {
        return $this->db->transaction(function () use ($userId, $listId, $parsed, $addedBy): array {
            $byKey = $parsed['dedupe_key'] !== null
                ? $this->db->fetchOne(
                    'SELECT * FROM shopping_items WHERE list_id = ? AND bought = 0 AND dedupe_key = ?',
                    [$listId, $parsed['dedupe_key']]
                )
                : null;
            $byName = $this->db->fetchOne(
                'SELECT * FROM shopping_items WHERE list_id = ? AND bought = 0 AND name_key = ? AND unit_key = ?',
                [$listId, $parsed['name_key'], $parsed['unit_key']]
            );
            if ($byKey === null && $byName === null) {
                $open = $this->scalar(
                    'SELECT COUNT(*) FROM shopping_items WHERE list_id = ? AND bought = 0',
                    [$listId]
                );
                if ($open >= self::MAX_OPEN) {
                    throw ValidationException::withFields(['name' => 'This list is full.']);
                }
                $id = (int) $this->db->fetchScalar(
                    'INSERT INTO shopping_items
                        (list_id, user_id, name, name_key, quantity, unit, unit_key, store, category, notes, added_by,
                         source, dedupe_key, needed_by, order_wave, trip_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [
                        $listId, $userId, $parsed['name'], $parsed['name_key'], $parsed['quantity'],
                        $parsed['unit'], $parsed['unit_key'], $parsed['store'], $parsed['category'],
                        $parsed['notes'], $addedBy, $parsed['source'], $parsed['dedupe_key'],
                        $parsed['needed_by'], $parsed['order_wave'],
                        $this->ownedTrip($userId, $parsed['trip_id']),
                    ]
                );
                $item = $this->itemById($userId, $id);
                $item['merged'] = false;
                return $item;
            }
            if ($byKey === null) {
                $existingKey = $this->blankToNull($byName['dedupe_key'] ?? null);
                if ($existingKey !== null && $parsed['dedupe_key'] !== null && $existingKey !== $parsed['dedupe_key']) {
                    throw ValidationException::withFields(['dedupe_key' => 'That item is already on the list with a different dedupe_key.']);
                }
                $this->applyMerge($userId, $byName, $parsed, false);
                $item = $this->itemById($userId, (int) $byName['id']);
                $item['merged'] = true;
                return $item;
            }
            $target = $byKey;
            if ($byName !== null && (int) $byName['id'] !== (int) $target['id']) {
                if (!$this->keysCompatible($this->blankToNull($target['dedupe_key'] ?? null), $this->blankToNull($byName['dedupe_key'] ?? null))) {
                    throw ValidationException::withFields(['dedupe_key' => 'That item is already on the list with a different dedupe_key.']);
                }
                $target['quantity'] = (float) $target['quantity'] + (float) $byName['quantity'];
                $target = $this->fillBlanks($target, $byName);
                $this->db->execute('DELETE FROM shopping_items WHERE id = ? AND user_id = ?', [(int) $byName['id'], $userId]);
            }
            $this->applyMerge($userId, $target, $parsed, true);
            $item = $this->itemById($userId, (int) $target['id']);
            $item['merged'] = true;
            return $item;
        });
    }

    /**
     * @param array<string, mixed> $row
     * @param array{name: string, name_key: string, quantity: float, unit: ?string, unit_key: string, category: string, store: ?string, notes: ?string, source: ?string, dedupe_key: ?string, needed_by: ?string, order_wave: ?string} $parsed
     */
    private function applyMerge(int $userId, array $row, array $parsed, bool $refreshIdentity): void
    {
        $quantity = round((float) $row['quantity'] + $parsed['quantity'], 3);
        if ($quantity <= 0 || $quantity > 9999) {
            throw ValidationException::withFields(['quantity' => 'Quantity must be greater than 0.']);
        }
        $name = $refreshIdentity ? $parsed['name'] : (string) $row['name'];
        $nameKey = $refreshIdentity ? $parsed['name_key'] : (string) $row['name_key'];
        $unit = $refreshIdentity ? $parsed['unit'] : $this->blankToNull($row['unit'] ?? null);
        $unitKey = $refreshIdentity ? $parsed['unit_key'] : (string) $row['unit_key'];
        $category = $refreshIdentity ? $parsed['category'] : (string) $row['category'];
        $store = $this->filled($row['store'] ?? null, $parsed['store']);
        $notes = $this->filled($row['notes'] ?? null, $parsed['notes']);
        $source = $parsed['source'] ?? $this->blankToNull($row['source'] ?? null);
        $dedupe = $parsed['dedupe_key'] ?? $this->blankToNull($row['dedupe_key'] ?? null);
        $needed = $parsed['needed_by'] ?? $this->blankToNull($row['needed_by'] ?? null);
        $wave = $parsed['order_wave'] ?? $this->blankToNull($row['order_wave'] ?? null);
        $tripId = $parsed['trip_id'] !== null
            ? $this->ownedTrip($userId, $parsed['trip_id'])
            : ($row['trip_id'] !== null && $row['trip_id'] !== '' ? (int) $row['trip_id'] : null);
        $this->assertOpenIdentity((int) $row['list_id'], (int) $row['id'], $nameKey, $unitKey, $dedupe);
        $this->db->execute(
            "UPDATE shopping_items
             SET name = ?, name_key = ?, quantity = ?, unit = ?, unit_key = ?, category = ?, store = ?, notes = ?,
                 source = ?, dedupe_key = ?, needed_by = ?, order_wave = ?, trip_id = ?,
                 updated_at = (now() AT TIME ZONE 'UTC')
             WHERE id = ? AND user_id = ?",
            [
                $name, $nameKey, $quantity, $unit, $unitKey, $category, $store, $notes,
                $source, $dedupe, $needed, $wave, $tripId, (int) $row['id'], $userId,
            ]
        );
    }

    /**
     * @param array<string, mixed>|string $raw
     * @return array{name: string, name_key: string, quantity: float, unit: ?string, unit_key: string, category: string, store: ?string, notes: ?string, source: ?string, dedupe_key: ?string, needed_by: ?string, order_wave: ?string}
     */
    private function normalizeInput(mixed $raw): array
    {
        if (is_string($raw)) {
            $parsed = ShoppingText::parse($raw);
            $parsed['store'] = null;
            $parsed['notes'] = null;
            $parsed['source'] = null;
            $parsed['dedupe_key'] = null;
            $parsed['needed_by'] = null;
            $parsed['order_wave'] = null;
            $parsed['trip_id'] = null;
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
        $parsed['source'] = $this->bounded($raw['source'] ?? null, 64, 'source');
        $parsed['dedupe_key'] = $this->bounded($raw['dedupe_key'] ?? null, 160, 'dedupe_key');
        $parsed['needed_by'] = $this->planDate($raw['needed_by'] ?? null);
        $parsed['order_wave'] = $this->bounded($raw['order_wave'] ?? null, 80, 'order_wave', true);
        $parsed['trip_id'] = $this->tripId($raw['trip_id'] ?? null);
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
            "SELECT list_id, COUNT(*) AS n FROM shopping_items
             WHERE user_id = ? AND bought = 0
               AND (order_wave IS NULL OR lower(btrim(order_wave)) <> 'deferred')
             GROUP BY list_id",
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
    private function tripId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $value = (int) $value;
        }
        if (is_bool($value) || !is_int($value) || $value < 1) {
            throw ValidationException::withFields(['trip_id' => 'trip_id must be a trip.']);
        }

        return $value;
    }

    private function ownedTrip(int $userId, ?int $tripId): ?int
    {
        if ($tripId === null) {
            return null;
        }
        $row = $this->db->fetchOne('SELECT id FROM trips WHERE id = ? AND user_id = ?', [$tripId, $userId]);
        if ($row === null) {
            throw ValidationException::withFields(['trip_id' => 'That trip was not found.']);
        }

        return $tripId;
    }

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
            'notes'       => $row['notes'] !== null && $row['notes'] !== '' ? (string) $row['notes'] : null,
            'source'      => $this->blankToNull($row['source'] ?? null),
            'dedupe_key'  => $this->blankToNull($row['dedupe_key'] ?? null),
            'needed_by'   => $this->blankToNull($row['needed_by'] ?? null),
            'order_wave'  => $this->blankToNull($row['order_wave'] ?? null),
            'trip_id'     => isset($row['trip_id']) && $row['trip_id'] !== null && $row['trip_id'] !== '' ? (int) $row['trip_id'] : null,
            'added_by'    => (string) $row['added_by'],
            'bought'      => (int) $row['bought'] === 1,
            'bought_at'   => $row['bought_at'] !== null ? (string) $row['bought_at'] : null,
        ];
    }

    private function assertOpenIdentity(int $listId, int $exceptId, string $nameKey, string $unitKey, ?string $dedupe): void
    {
        if ($dedupe !== null) {
            $keyClash = $this->db->fetchOne(
                'SELECT id FROM shopping_items WHERE list_id = ? AND bought = 0 AND dedupe_key = ? AND id <> ?',
                [$listId, $dedupe, $exceptId]
            );
            if ($keyClash !== null) {
                throw ValidationException::withFields(['dedupe_key' => 'Another open item already uses that dedupe_key.']);
            }
        }
        $nameClash = $this->db->fetchOne(
            'SELECT id FROM shopping_items
             WHERE list_id = ? AND bought = 0 AND name_key = ? AND unit_key = ? AND id <> ?',
            [$listId, $nameKey, $unitKey, $exceptId]
        );
        if ($nameClash !== null) {
            throw ValidationException::withFields(['name' => 'That item is already on the list.']);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function openConflicts(int $listId, int $exceptId, string $nameKey, string $unitKey, ?string $dedupe): array
    {
        if ($dedupe !== null) {
            return $this->db->fetchAll(
                'SELECT * FROM shopping_items
                 WHERE list_id = ? AND bought = 0 AND id <> ?
                   AND ((name_key = ? AND unit_key = ?) OR dedupe_key = ?)
                 ORDER BY id',
                [$listId, $exceptId, $nameKey, $unitKey, $dedupe]
            );
        }
        return $this->db->fetchAll(
            'SELECT * FROM shopping_items
             WHERE list_id = ? AND bought = 0 AND id <> ? AND name_key = ? AND unit_key = ?
             ORDER BY id',
            [$listId, $exceptId, $nameKey, $unitKey]
        );
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $from
     * @return array<string, mixed>
     */
    private function fillBlanks(array $target, array $from): array
    {
        foreach (['store', 'notes', 'source', 'dedupe_key', 'needed_by', 'order_wave'] as $field) {
            if ($this->blankToNull($target[$field] ?? null) === null) {
                $target[$field] = $from[$field] ?? null;
            }
        }
        return $target;
    }

    private function filled(mixed $existing, ?string $incoming): ?string
    {
        return $this->blankToNull($existing) ?? $incoming;
    }

    private function keysCompatible(?string $left, ?string $right): bool
    {
        return $left === null || $right === null || $left === $right;
    }

    private function blankToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function bounded(mixed $value, int $max, string $field, bool $collapse = false): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($collapse) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        }
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $max) {
            $label = $field === 'dedupe_key' ? 'dedupe_key' : ucfirst($field);
            throw ValidationException::withFields([$field => $label . " must be {$max} characters or fewer."]);
        }
        return $text;
    }

    private function planDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        try {
            return Clock::normalizeDate($text);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withFields(['needed_by' => 'Date must be YYYY-MM-DD.']);
        }
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
