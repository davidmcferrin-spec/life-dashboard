<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

use Dashboard\DB\Database;
use Dashboard\Settings\PreferenceService;
use Dashboard\Shopping\ShoppingService;
use Dashboard\Shopping\ShoppingText;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * On-hand food and household lots. Use-by dates follow PantryDates.
 * Writes record a pantry_events row. The caller passes who did it.
 */
final class PantryService
{
    private const MAX_BATCH = 40;

    private const SOON_DAYS = 3;

    /** @var array<string, string> */
    private const UNITS = [
        'each' => 'each', 'ct' => 'ct', 'count' => 'ct',
        'head' => 'head', 'bunch' => 'bunch',
        'lb' => 'lb', 'lbs' => 'lb', 'oz' => 'oz',
        'g' => 'g', 'kg' => 'kg',
        'gal' => 'gal', 'qt' => 'qt', 'pt' => 'pt',
        'ml' => 'ml', 'l' => 'l',
        'pkg' => 'pkg', 'pack' => 'pkg', 'bag' => 'bag', 'box' => 'box',
        'can' => 'can', 'bottle' => 'bottle', 'dozen' => 'dozen',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly ShoppingService $shopping,
        private readonly ?ProductLookup $lookup = null,
    ) {}

    /**
     * @param array<string, mixed> $filters
     * @return array{lots: list<array<string, mixed>>, soon_days: int}
     */
    public function listLots(int $userId, array $filters): array
    {
        $this->ensureDefaults($userId);
        $soon = $this->soonDays($filters['soon_days'] ?? self::SOON_DAYS);
        $where = ['l.user_id = ?'];
        $params = [$userId];
        $status = $this->optionalEnum($filters['status'] ?? 'on_hand', ['on_hand', 'used', 'tossed', 'expired', 'any'], 'status');
        if ($status === 'any') {
            $where[] = "l.status <> 'tossed'";
        } else {
            $where[] = 'l.status = ?';
            $params[] = $status;
        }
        if (isset($filters['location']) && $filters['location'] !== '' && $filters['location'] !== null) {
            $where[] = 'l.location = ?';
            $params[] = $this->location((string) $filters['location']);
        }
        if (isset($filters['category']) && trim((string) $filters['category']) !== '') {
            $where[] = 'p.category = ?';
            $params[] = PantryCatalog::categoryName((string) $filters['category'], 'item');
        }
        if (isset($filters['expiring_within_days']) && $filters['expiring_within_days'] !== '' && $filters['expiring_within_days'] !== null) {
            $days = $this->windowDays($filters['expiring_within_days']);
            $where[] = 'l.status = \'on_hand\' AND l.use_by IS NOT NULL AND l.use_by <= ?';
            $params[] = PantryDates::addDays($this->clock->today(), $days);
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(p.name_key LIKE ? OR p.upc = ? OR p.brand ILIKE ?)';
            $params[] = '%' . $this->like($this->nameKey($search)) . '%';
            $params[] = preg_replace('/\D/', '', $search) ?? '';
            $params[] = '%' . $this->like($search) . '%';
        }
        $sql = 'SELECT l.*, p.name, p.brand, p.upc, p.category, p.name_key
                FROM pantry_lots l
                JOIN pantry_products p ON p.id = l.product_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY l.location, l.use_by ASC NULLS LAST, p.name_key, l.id';
        $rows = $this->db->fetchAll($sql, $params);
        return [
            'lots' => array_map(fn (array $row): array => $this->publicLot($row, $soon), $rows),
            'soon_days' => $soon,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(int $userId, int $soonDays = self::SOON_DAYS): array
    {
        $this->ensureDefaults($userId);
        $soonDays = $this->soonDays($soonDays);
        $today = $this->clock->today();
        $soon = PantryDates::addDays($today, $soonDays);
        $counts = ['pantry' => 0, 'fridge' => 0, 'freezer' => 0];
        foreach ($this->db->fetchAll(
            "SELECT location, COUNT(*) AS n FROM pantry_lots WHERE user_id = ? AND status = 'on_hand' GROUP BY location",
            [$userId]
        ) as $row) {
            $counts[(string) $row['location']] = (int) $row['n'];
        }
        $expiring = $this->expiring($userId, $soonDays)['lots'];
        $low = $this->lowStock($userId, false, null, 'david')['products'];
        return [
            'metric_ids' => [],
            'soon_days' => $soonDays,
            'counts' => $counts,
            'expiring' => array_slice($expiring, 0, 5),
            'expiring_count' => count($expiring),
            'low_stock' => array_slice($low, 0, 5),
            'low_stock_count' => count($low),
            'today' => $today,
            'soon_on' => $soon,
        ];
    }

    /**
     * @return array{lots: list<array<string, mixed>>, days: int}
     */
    public function expiring(int $userId, int $days): array
    {
        $this->ensureDefaults($userId);
        $days = $this->windowDays($days);
        $until = PantryDates::addDays($this->clock->today(), $days);
        $rows = $this->db->fetchAll(
            "SELECT l.*, p.name, p.brand, p.upc, p.category
             FROM pantry_lots l
             JOIN pantry_products p ON p.id = l.product_id
             WHERE l.user_id = ? AND l.status = 'on_hand' AND l.use_by IS NOT NULL AND l.use_by <= ?
             ORDER BY l.use_by, p.name_key, l.id",
            [$userId, $until]
        );
        return [
            'days' => $days,
            'lots' => array_map(fn (array $row): array => $this->publicLot($row, $days), $rows),
        ];
    }

    /**
     * @param list<mixed> $lotIds
     * @return array{tossed: list<array<string, mixed>>}
     */
    public function toss(int $userId, array $lotIds, string $by): array
    {
        $ids = $this->idList($lotIds, 'lot_ids');
        return $this->db->transaction(function () use ($userId, $ids, $by): array {
            $tossed = [];
            foreach ($ids as $id) {
                $lot = $this->lockLot($userId, $id);
                if ($lot['status'] !== 'on_hand') {
                    throw ValidationException::withFields(['lot_ids' => 'Lot ' . $id . ' is already ' . $lot['status'] . '.']);
                }
                $this->db->execute(
                    "UPDATE pantry_lots SET status = 'tossed', updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
                    [$id, $userId]
                );
                $this->event($userId, $id, 'toss', (string) $lot['quantity'], $by, null);
                $tossed[] = $this->lot($userId, $id);
            }
            return ['tossed' => $tossed];
        });
    }

    /**
     * @return array{products: list<array<string, mixed>>, added: list<array<string, mixed>>, list_id: ?int}
     */
    public function lowStock(int $userId, bool $add, ?int $listId, string $by): array
    {
        $this->ensureDefaults($userId);
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.name, p.category, p.default_unit, p.min_on_hand,
                    COALESCE(SUM(l.quantity) FILTER (WHERE l.status = 'on_hand'), 0) AS on_hand
             FROM pantry_products p
             LEFT JOIN pantry_lots l ON l.product_id = p.id AND l.user_id = p.user_id
             WHERE p.user_id = ? AND p.min_on_hand IS NOT NULL
             GROUP BY p.id
             HAVING COALESCE(SUM(l.quantity) FILTER (WHERE l.status = 'on_hand'), 0) < p.min_on_hand
             ORDER BY p.name_key",
            [$userId]
        );
        $products = [];
        foreach ($rows as $row) {
            $products[] = [
                'product_id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'unit' => $row['default_unit'] !== null ? (string) $row['default_unit'] : null,
                'min_on_hand' => $this->num($row['min_on_hand']),
                'on_hand' => $this->num($row['on_hand']),
                'short_by' => round($this->num($row['min_on_hand']) - $this->num($row['on_hand']), 3),
            ];
        }
        $added = [];
        $usedList = null;
        if ($add && $products !== []) {
            $usedList = $this->groceriesId($userId, $listId);
            $items = [];
            foreach ($products as $product) {
                $items[] = [
                    'name' => $product['name'],
                    'quantity' => max(0.001, $product['short_by']),
                    'unit' => $this->shoppingUnit($product['unit']),
                    'category' => $product['category'],
                ];
            }
            $addedBy = $by === 'user' ? 'user' : 'agent';
            $added = $this->shopping->addItems($userId, $usedList, $items, $addedBy, true);
        }
        return ['products' => $products, 'added' => $added, 'list_id' => $usedList];
    }

    /**
     * @return array<string, mixed>
     */
    public function stats(int $userId, ?string $from, ?string $to): array
    {
        $to = $to !== null && $to !== '' ? $this->date($to, 'to') : $this->clock->today();
        $from = $from !== null && $from !== '' ? $this->date($from, 'from') : PantryDates::addDays($to, -30);
        if ($from > $to) {
            throw ValidationException::withFields(['from' => 'from must be on or before to.']);
        }
        $rows = $this->db->fetchAll(
            "SELECT p.id, p.name, e.action, COALESCE(SUM(e.quantity), 0) AS qty, COUNT(*) AS n
             FROM pantry_events e
             JOIN pantry_lots l ON l.id = e.lot_id
             JOIN pantry_products p ON p.id = l.product_id
             WHERE e.user_id = ? AND e.at >= ? AND e.at < ? AND e.action IN ('consume', 'toss')
             GROUP BY p.id, p.name, e.action
             ORDER BY p.name",
            [$userId, $from . ' 00:00:00', PantryDates::addDays($to, 1) . ' 00:00:00']
        );
        $by = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            if (!isset($by[$id])) {
                $by[$id] = [
                    'product_id' => $id,
                    'name' => (string) $row['name'],
                    'used_qty' => 0.0,
                    'used_count' => 0,
                    'tossed_qty' => 0.0,
                    'tossed_count' => 0,
                ];
            }
            $slot = (string) $row['action'] === 'toss' ? 'tossed' : 'used';
            $by[$id][$slot . '_qty'] = $this->num($row['qty']);
            $by[$id][$slot . '_count'] = (int) $row['n'];
        }
        return ['from' => $from, 'to' => $to, 'products' => array_values($by)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listProducts(int $userId, ?string $search): array
    {
        $this->ensureDefaults($userId);
        $params = [$userId];
        $sql = 'SELECT * FROM pantry_products WHERE user_id = ?';
        $search = trim((string) $search);
        if ($search !== '') {
            $sql .= ' AND (name_key LIKE ? OR upc = ? OR brand ILIKE ?)';
            $params[] = '%' . $this->like($this->nameKey($search)) . '%';
            $params[] = preg_replace('/\D/', '', $search) ?? '';
            $params[] = '%' . $this->like($search) . '%';
        }
        $sql .= ' ORDER BY name_key, id';
        return array_map(fn (array $row): array => $this->publicProduct($userId, $row), $this->db->fetchAll($sql, $params));
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function upsertProduct(int $userId, array $fields): array
    {
        $this->ensureDefaults($userId);
        $id = isset($fields['product_id']) && $fields['product_id'] !== '' && $fields['product_id'] !== null
            ? (int) $fields['product_id']
            : 0;
        $existing = $id > 0 ? $this->requireProduct($userId, $id) : null;
        $upc = $this->nullableUpc($fields['upc'] ?? ($existing['upc'] ?? null));
        $name = $this->productName((string) ($fields['name'] ?? ($existing['name'] ?? '')));
        $category = PantryCatalog::categoryName(
            isset($fields['category']) ? (string) $fields['category'] : ($existing !== null ? (string) $existing['category'] : null),
            $name
        );
        $brand = array_key_exists('brand', $fields) ? $this->optionalText($fields['brand'], 120, 'brand') : ($existing['brand'] ?? null);
        $size = array_key_exists('default_size', $fields) ? $this->optionalText($fields['default_size'], 80, 'default_size') : ($existing['default_size'] ?? null);
        $unit = array_key_exists('default_unit', $fields)
            ? $this->nullableUnit($fields['default_unit'])
            : ($existing['default_unit'] ?? null);
        $notes = array_key_exists('notes', $fields) ? $this->optionalText($fields['notes'], 2000, 'notes') : ($existing['notes'] ?? null);
        $image = array_key_exists('image_url', $fields) ? $this->imageUrl($fields['image_url']) : ($existing['image_url'] ?? null);
        $days = [
            'shelf_life_pantry_days' => $this->nullableDays($fields, 'shelf_life_pantry_days', $existing),
            'shelf_life_fridge_days' => $this->nullableDays($fields, 'shelf_life_fridge_days', $existing),
            'shelf_life_freezer_days' => $this->nullableDays($fields, 'shelf_life_freezer_days', $existing),
            'opened_life_days' => $this->nullableDays($fields, 'opened_life_days', $existing),
        ];
        $min = array_key_exists('min_on_hand', $fields) ? $this->nullableQty($fields['min_on_hand'], 'min_on_hand', true) : ($existing['min_on_hand'] ?? null);
        $this->assertUpcFree($userId, $upc, $existing !== null ? (int) $existing['id'] : null);
        if ($existing === null) {
            $newId = (int) $this->db->fetchScalar(
                'INSERT INTO pantry_products (
                    user_id, upc, name, name_key, brand, category, default_size, default_unit,
                    shelf_life_pantry_days, shelf_life_fridge_days, shelf_life_freezer_days, opened_life_days,
                    min_on_hand, image_url, notes, source
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [
                    $userId, $upc, $name, $this->nameKey($name), $brand, $category, $size, $unit,
                    $days['shelf_life_pantry_days'], $days['shelf_life_fridge_days'], $days['shelf_life_freezer_days'], $days['opened_life_days'],
                    $min, $image, $notes, 'manual',
                ]
            );
            return $this->publicProduct($userId, $this->requireProduct($userId, $newId));
        }
        $this->db->execute(
            'UPDATE pantry_products SET
                upc = ?, name = ?, name_key = ?, brand = ?, category = ?, default_size = ?, default_unit = ?,
                shelf_life_pantry_days = ?, shelf_life_fridge_days = ?, shelf_life_freezer_days = ?, opened_life_days = ?,
                min_on_hand = ?, image_url = ?, notes = ?, source = ?, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            [
                $upc, $name, $this->nameKey($name), $brand, $category, $size, $unit,
                $days['shelf_life_pantry_days'], $days['shelf_life_fridge_days'], $days['shelf_life_freezer_days'], $days['opened_life_days'],
                $min, $image, $notes, 'manual', (int) $existing['id'], $userId,
            ]
        );
        return $this->publicProduct($userId, $this->requireProduct($userId, (int) $existing['id']));
    }

    /**
     * @return array<string, mixed>
     */
    public function productByUpc(int $userId, string $upc, bool $external): array
    {
        $this->ensureDefaults($userId);
        $digits = ProductLookup::requireUpc($upc);
        $local = $this->productByUpcVariants($userId, $digits);
        $suggestion = null;
        $error = null;
        if ($local === null && $external) {
            $looked = $this->lookup()->external($digits);
            $suggestion = $looked['suggestion'];
            $error = $looked['lookup_error'];
        }
        return [
            'upc' => $digits,
            'product' => $local !== null ? $this->publicProduct($userId, $local) : null,
            'suggestion' => $suggestion,
            'lookup_error' => $error,
        ];
    }

    /**
     * @param list<mixed> $items
     * @return array{lots: list<array<string, mixed>>}
     */
    public function addItems(int $userId, array $items, string $by): array
    {
        $this->ensureDefaults($userId);
        if ($items === []) {
            throw ValidationException::withFields(['items' => 'Add at least one item.']);
        }
        if (count($items) > self::MAX_BATCH) {
            throw ValidationException::withFields(['items' => 'Add up to ' . self::MAX_BATCH . ' items at a time.']);
        }
        return $this->db->transaction(function () use ($userId, $items, $by): array {
            $lots = [];
            foreach ($items as $i => $raw) {
                if (!is_array($raw)) {
                    throw ValidationException::withFields(["items.{$i}" => 'Each item must be an object.']);
                }
                try {
                    $lots[] = $this->addOne($userId, $raw, $by);
                } catch (ValidationException $e) {
                    $fields = [];
                    foreach ($e->fields() as $key => $message) {
                        $fields["items.{$i}.{$key}"] = $message;
                    }
                    throw ValidationException::withFields($fields !== [] ? $fields : ["items.{$i}" => $e->getMessage()]);
                }
            }
            return ['lots' => $lots];
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array{consumed: list<array<string, mixed>>, quantity: float}
     */
    public function consume(int $userId, array $input, string $by): array
    {
        $this->ensureDefaults($userId);
        $quantity = $this->qty($input['quantity'] ?? 1, 'quantity');
        return $this->db->transaction(function () use ($userId, $input, $by, $quantity): array {
            if (isset($input['lot_id']) && $input['lot_id'] !== '' && $input['lot_id'] !== null) {
                $productId = (int) $this->lockLot($userId, (int) $input['lot_id'])['product_id'];
                $only = (int) $input['lot_id'];
            } else {
                $product = $this->matchProduct($userId, $input, false);
                $productId = (int) $product['id'];
                $only = null;
            }
            $sql = "SELECT id, quantity FROM pantry_lots
                    WHERE user_id = ? AND product_id = ? AND status = 'on_hand'";
            $params = [$userId, $productId];
            if ($only !== null) {
                $sql .= ' AND id = ?';
                $params[] = $only;
            }
            $sql .= ' ORDER BY use_by ASC NULLS LAST, purchased_at ASC, id ASC FOR UPDATE';
            $lots = $this->db->fetchAll($sql, $params);
            $have = 0.0;
            foreach ($lots as $lot) {
                $have += (float) $lot['quantity'];
            }
            $need = (float) $quantity;
            if ($have + 0.0005 < $need) {
                $name = (string) $this->requireProduct($userId, $productId)['name'];
                throw ValidationException::withFields([
                    'quantity' => 'Not enough ' . $name . ' on hand (have ' . $this->trimNum($have) . ', asked ' . $this->trimNum($need) . ').',
                ]);
            }
            $left = $need;
            $touched = [];
            foreach ($lots as $lot) {
                if ($left <= 0.0005) {
                    break;
                }
                $haveQty = (float) $lot['quantity'];
                $take = min($haveQty, $left);
                $remain = round($haveQty - $take, 3);
                if ($remain <= 0.0005) {
                    $this->db->execute(
                        "UPDATE pantry_lots SET quantity = 0, status = 'used', updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
                        [(int) $lot['id'], $userId]
                    );
                } else {
                    $this->db->execute(
                        "UPDATE pantry_lots SET quantity = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
                        [$this->trimNum($remain), (int) $lot['id'], $userId]
                    );
                }
                $this->event($userId, (int) $lot['id'], 'consume', $this->trimNum($take), $by, null);
                $touched[] = $this->lot($userId, (int) $lot['id']);
                $left = round($left - $take, 3);
            }
            return ['consumed' => $touched, 'quantity' => $need];
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function splitOrMove(int $userId, int $lotId, array $body, string $by): array
    {
        return $this->db->transaction(function () use ($userId, $lotId, $body, $by): array {
            $lot = $this->lockLot($userId, $lotId);
            $this->requireOnHand($lot);
            $pieces = $body['pieces'] ?? null;
            if (is_array($pieces) && $pieces !== []) {
                return $this->splitPieces($userId, $lot, $pieces, $by);
            }
            if (!isset($body['location']) || $body['location'] === '') {
                throw ValidationException::withFields(['location' => 'Set location, or pass pieces.']);
            }
            $location = $this->location((string) $body['location']);
            $reset = filter_var($body['reset_use_by'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $qty = isset($body['quantity']) && $body['quantity'] !== '' && $body['quantity'] !== null
                ? (float) $this->qty($body['quantity'], 'quantity')
                : (float) $lot['quantity'];
            if ($qty - (float) $lot['quantity'] > 0.0005) {
                throw ValidationException::withFields(['quantity' => 'That is more than this lot has.']);
            }
            if (abs($qty - (float) $lot['quantity']) <= 0.0005) {
                return ['lot' => $this->moveWhole($userId, $lot, $location, $reset, $by)];
            }
            return $this->splitOff($userId, $lot, $qty, $location, $by);
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function openLot(int $userId, int $lotId, string $by): array
    {
        return $this->db->transaction(function () use ($userId, $lotId, $by): array {
            $lot = $this->lockLot($userId, $lotId);
            $this->requireOnHand($lot);
            if ($lot['opened_at'] !== null) {
                throw ValidationException::withFields(['lot_id' => 'This lot is already open.']);
            }
            $openedOn = $this->clock->today();
            $product = $this->requireProduct($userId, (int) $lot['product_id']);
            $days = $this->openedDays($userId, $product);
            $next = PantryDates::afterOpen(
                $lot['use_by'] !== null ? (string) $lot['use_by'] : null,
                $openedOn,
                $days,
                (int) $lot['use_by_overridden'] === 1
            );
            $this->db->execute(
                'UPDATE pantry_lots SET opened_at = ?, use_by = ?, use_by_overridden = ?, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [$this->clock->utcString($this->clock->now()), $next['use_by'], $next['overridden'] ? 1 : 0, $lotId, $userId]
            );
            $this->event($userId, $lotId, 'open', (string) $lot['quantity'], $by, null);
            return ['lot' => $this->lot($userId, $lotId)];
        });
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function adjust(int $userId, int $lotId, array $fields, string $by): array
    {
        return $this->db->transaction(function () use ($userId, $lotId, $fields, $by): array {
            $lot = $this->lockLot($userId, $lotId);
            $this->requireOnHand($lot);
            $quantity = array_key_exists('quantity', $fields) ? (float) $this->qtyAllowZero($fields['quantity']) : (float) $lot['quantity'];
            $location = isset($fields['location']) ? $this->location((string) $fields['location']) : (string) $lot['location'];
            $unit = isset($fields['unit']) ? $this->unit((string) $fields['unit']) : (string) $lot['unit'];
            $level = array_key_exists('qty_level', $fields) ? $this->level($fields['qty_level']) : ($lot['qty_level'] !== null ? (string) $lot['qty_level'] : null);
            $store = array_key_exists('store', $fields) ? $this->optionalText($fields['store'], 80, 'store') : ($lot['store'] !== null ? (string) $lot['store'] : null);
            $purchased = isset($fields['purchased_at']) ? $this->date((string) $fields['purchased_at'], 'purchased_at') : (string) $lot['purchased_at'];
            $moved = $location !== (string) $lot['location'];
            $reset = filter_var($fields['reset_use_by'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $product = $this->requireProduct($userId, (int) $lot['product_id']);
            $useBy = $lot['use_by'] !== null ? (string) $lot['use_by'] : null;
            $overridden = (int) $lot['use_by_overridden'] === 1;
            if ($moved || $reset) {
                $next = PantryDates::afterMove(
                    $location,
                    $purchased,
                    $this->clock->today(),
                    $useBy,
                    $overridden,
                    $reset,
                    $this->locationDays($userId, $product, $location)
                );
                $useBy = $next['use_by'];
                $overridden = $next['overridden'];
            }
            if (array_key_exists('use_by', $fields)) {
                $useBy = $fields['use_by'] === null || $fields['use_by'] === '' ? null : $this->date((string) $fields['use_by'], 'use_by');
                $overridden = $useBy !== null;
            }
            $status = $quantity <= 0.0005 ? 'used' : 'on_hand';
            $this->db->execute(
                'UPDATE pantry_lots SET quantity = ?, unit = ?, qty_level = ?, location = ?, purchased_at = ?, store = ?,
                    use_by = ?, use_by_overridden = ?, status = ?, updated_at = (now() AT TIME ZONE \'UTC\')
                 WHERE id = ? AND user_id = ?',
                [
                    $this->trimNum($quantity), $unit, $level, $location, $purchased, $store,
                    $useBy, $overridden ? 1 : 0, $status, $lotId, $userId,
                ]
            );
            $delta = abs($quantity - (float) $lot['quantity']);
            $action = $moved && abs($delta) <= 0.0005 ? 'move' : 'adjust';
            $this->event($userId, $lotId, $action, $this->trimNum($action === 'move' ? $quantity : $delta), $by, null);
            return ['lot' => $this->lot($userId, $lotId)];
        });
    }

    /**
     * @param array<string, mixed> $body
     * @return array{lots: list<array<string, mixed>>, unmatched: list<array<string, mixed>>}
     */
    public function importOrder(int $userId, array $body, string $by): array
    {
        $this->ensureDefaults($userId);
        $store = $this->optionalText($body['store'] ?? null, 80, 'store');
        $orderRef = $this->optionalText($body['order_ref'] ?? null, 80, 'order_ref');
        $purchased = isset($body['purchased_at']) && $body['purchased_at'] !== ''
            ? $this->date((string) $body['purchased_at'], 'purchased_at')
            : $this->clock->today();
        $lines = $body['items'] ?? $body['lines'] ?? null;
        if (!is_array($lines) || $lines === []) {
            throw ValidationException::withFields(['items' => 'Add at least one line.']);
        }
        if (count($lines) > self::MAX_BATCH) {
            throw ValidationException::withFields(['items' => 'Import up to ' . self::MAX_BATCH . ' lines at a time.']);
        }
        return $this->db->transaction(function () use ($userId, $lines, $store, $orderRef, $purchased, $by): array {
            $lots = [];
            $unmatched = [];
            foreach ($lines as $i => $line) {
                if (!is_array($line)) {
                    $unmatched[] = ['index' => $i, 'reason' => 'Each line must be an object.'];
                    continue;
                }
                $matched = $this->matchOrderLine($userId, $line);
                $product = $matched['product'];
                if ($product === null) {
                    $unmatched[] = [
                        'index' => $i,
                        'name' => isset($line['name']) ? mb_substr(trim((string) $line['name']), 0, 200) : null,
                        'upc' => isset($line['upc']) ? mb_substr(trim((string) $line['upc']), 0, 32) : null,
                        'reason' => $matched['reason'],
                    ];
                    continue;
                }
                $lots[] = $this->addOne($userId, [
                    'product_id' => (int) $product['id'],
                    'quantity' => $line['quantity'] ?? $line['qty'] ?? 1,
                    'unit' => $line['unit'] ?? $product['default_unit'],
                    'location' => $line['location'] ?? 'pantry',
                    'purchased_at' => $purchased,
                    'store' => $line['store'] ?? $store,
                    'order_ref' => $orderRef,
                    'unit_price' => $line['unit_price'] ?? $line['price'] ?? null,
                ], $by);
            }
            return ['lots' => $lots, 'unmatched' => $unmatched];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function categories(int $userId): array
    {
        $this->ensureDefaults($userId);
        $rows = $this->db->fetchAll(
            'SELECT category, pantry_days, fridge_days, freezer_days, opened_days FROM category_shelf_life WHERE user_id = ? ORDER BY category',
            [$userId]
        );
        return array_map(static function (array $row): array {
            return [
                'category' => (string) $row['category'],
                'pantry_days' => $row['pantry_days'] !== null ? (int) $row['pantry_days'] : null,
                'fridge_days' => $row['fridge_days'] !== null ? (int) $row['fridge_days'] : null,
                'freezer_days' => $row['freezer_days'] !== null ? (int) $row['freezer_days'] : null,
                'opened_days' => $row['opened_days'] !== null ? (int) $row['opened_days'] : null,
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateCategory(int $userId, string $category, array $fields): array
    {
        $this->ensureDefaults($userId);
        $name = PantryCatalog::categoryName($category, 'item');
        $current = $this->db->fetchOne(
            'SELECT pantry_days, fridge_days, freezer_days, opened_days FROM category_shelf_life WHERE user_id = ? AND category = ?',
            [$userId, $name]
        );
        if ($current === null) {
            throw new NotFoundException('Unknown category.');
        }
        $pantry = $this->keepDays($fields, 'pantry_days', $current['pantry_days']);
        $fridge = $this->keepDays($fields, 'fridge_days', $current['fridge_days']);
        $freezer = $this->keepDays($fields, 'freezer_days', $current['freezer_days']);
        $opened = $this->keepDays($fields, 'opened_days', $current['opened_days']);
        $this->db->execute(
            'UPDATE category_shelf_life SET pantry_days = ?, fridge_days = ?, freezer_days = ?, opened_days = ?, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE user_id = ? AND category = ?',
            [$pantry, $fridge, $freezer, $opened, $userId, $name]
        );
        foreach ($this->categories($userId) as $row) {
            if ($row['category'] === $name) {
                return $row;
            }
        }
        throw new NotFoundException('Unknown category.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecipes(int $userId): array
    {
        $rows = $this->db->fetchAll('SELECT id, name, notes FROM pantry_recipes WHERE user_id = ? ORDER BY name, id', [$userId]);
        return array_map(fn (array $row): array => $this->publicRecipe($userId, $row), $rows);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function upsertRecipe(int $userId, array $body): array
    {
        $name = $this->productName((string) ($body['name'] ?? ''));
        if (mb_strlen($name) > 160) {
            throw ValidationException::withFields(['name' => 'Name must be 160 characters or fewer.']);
        }
        $notes = $this->optionalText($body['notes'] ?? null, 4000, 'notes');
        $ingredients = $body['ingredients'] ?? [];
        if (!is_array($ingredients)) {
            throw ValidationException::withFields(['ingredients' => 'ingredients must be a list.']);
        }
        $id = isset($body['recipe_id']) && $body['recipe_id'] !== '' && $body['recipe_id'] !== null ? (int) $body['recipe_id'] : 0;
        return $this->db->transaction(function () use ($userId, $id, $name, $notes, $ingredients): array {
            if ($id > 0) {
                $existing = $this->db->fetchOne('SELECT id FROM pantry_recipes WHERE id = ? AND user_id = ?', [$id, $userId]);
                if ($existing === null) {
                    throw new NotFoundException('Recipe not found.');
                }
                $this->db->execute(
                    'UPDATE pantry_recipes SET name = ?, notes = ?, updated_at = (now() AT TIME ZONE \'UTC\') WHERE id = ? AND user_id = ?',
                    [$name, $notes, $id, $userId]
                );
                $this->db->execute('DELETE FROM pantry_recipe_ingredients WHERE recipe_id = ? AND user_id = ?', [$id, $userId]);
                $recipeId = $id;
            } else {
                $recipeId = (int) $this->db->fetchScalar(
                    'INSERT INTO pantry_recipes (user_id, name, notes) VALUES (?, ?, ?) RETURNING id',
                    [$userId, $name, $notes]
                );
            }
            foreach ($ingredients as $i => $ingredient) {
                if (!is_array($ingredient)) {
                    throw ValidationException::withFields(["ingredients.{$i}" => 'Each ingredient must be an object.']);
                }
                $productId = isset($ingredient['product_id']) && $ingredient['product_id'] !== '' && $ingredient['product_id'] !== null
                    ? (int) $ingredient['product_id']
                    : null;
                if ($productId !== null) {
                    $this->requireProduct($userId, $productId);
                }
                $category = isset($ingredient['category']) && trim((string) $ingredient['category']) !== ''
                    ? PantryCatalog::categoryName((string) $ingredient['category'], 'item')
                    : null;
                if ($productId === null && $category === null) {
                    throw ValidationException::withFields(["ingredients.{$i}" => 'Set product_id or category.']);
                }
                $qty = isset($ingredient['quantity']) && $ingredient['quantity'] !== '' && $ingredient['quantity'] !== null
                    ? $this->qty($ingredient['quantity'], 'quantity')
                    : null;
                $unit = isset($ingredient['unit']) && $ingredient['unit'] !== '' && $ingredient['unit'] !== null
                    ? $this->unit((string) $ingredient['unit'])
                    : null;
                $this->db->execute(
                    'INSERT INTO pantry_recipe_ingredients (recipe_id, user_id, product_id, category, quantity, unit) VALUES (?, ?, ?, ?, ?, ?)',
                    [$recipeId, $userId, $productId, $category, $qty, $unit]
                );
            }
            $row = $this->db->fetchOne('SELECT id, name, notes FROM pantry_recipes WHERE id = ? AND user_id = ?', [$recipeId, $userId]);
            return $this->publicRecipe($userId, $row ?? []);
        });
    }

    /**
     * @return array{recipes: list<array<string, mixed>>}
     */
    public function whatCanIMake(int $userId): array
    {
        $this->ensureDefaults($userId);
        $onHand = $this->db->fetchAll(
            "SELECT p.id, p.category, p.name, l.unit, l.use_by, l.quantity
             FROM pantry_lots l
             JOIN pantry_products p ON p.id = l.product_id
             WHERE l.user_id = ? AND l.status = 'on_hand'",
            [$userId]
        );
        $recipes = $this->listRecipes($userId);
        $ranked = [];
        foreach ($recipes as $recipe) {
            $ingredients = $recipe['ingredients'];
            $needed = count($ingredients);
            $covered = 0;
            $missing = [];
            $soonest = null;
            foreach ($ingredients as $ingredient) {
                $hit = $this->coverIngredient($onHand, $ingredient);
                if ($hit === null) {
                    $missing[] = $ingredient['label'];
                    continue;
                }
                $covered++;
                if ($hit !== '' && ($soonest === null || $hit < $soonest)) {
                    $soonest = $hit;
                }
            }
            $ranked[] = [
                'recipe_id' => $recipe['id'],
                'name' => $recipe['name'],
                'coverage' => $needed === 0 ? 0 : round($covered / $needed, 3),
                'covered' => $covered,
                'needed' => $needed,
                'missing' => $missing,
                'soonest_use_by' => $soonest,
            ];
        }
        usort($ranked, static function (array $a, array $b): int {
            $coverage = $b['coverage'] <=> $a['coverage'];
            if ($coverage !== 0) {
                return $coverage;
            }
            $aSoon = $a['soonest_use_by'] ?? '9999-99-99';
            $bSoon = $b['soonest_use_by'] ?? '9999-99-99';
            $soon = $aSoon <=> $bSoon;
            if ($soon !== 0) {
                return $soon;
            }
            return $a['name'] <=> $b['name'];
        });
        return ['recipes' => $ranked];
    }

    public function ensureDefaults(int $userId): void
    {
        $count = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM category_shelf_life WHERE user_id = ?', [$userId]);
        if ($count === 0) {
            foreach (PantryCatalog::CATEGORIES as $category => $days) {
                $this->db->execute(
                    'INSERT INTO category_shelf_life (user_id, category, pantry_days, fridge_days, freezer_days, opened_days)
                     VALUES (?, ?, ?, ?, ?, ?)',
                    [$userId, $category, $days['pantry'], $days['fridge'], $days['freezer'], $days['opened']]
                );
            }
        }
        $produce = (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM pantry_products WHERE user_id = ? AND source = 'produce'",
            [$userId]
        );
        if ($produce === 0) {
            foreach (PantryCatalog::PRODUCE as $item) {
                $this->db->execute(
                    'INSERT INTO pantry_products (
                        user_id, name, name_key, category, default_unit,
                        shelf_life_pantry_days, shelf_life_fridge_days, shelf_life_freezer_days, opened_life_days, source
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'produce\')',
                    [
                        $userId, $item['name'], $this->nameKey($item['name']), 'Produce', $item['unit'],
                        $item['pantry'], $item['fridge'], $item['freezer'], $item['opened'],
                    ]
                );
            }
        }
        $this->seedVault($userId);
    }

    private function seedVault(int $userId): void
    {
        $page = $this->db->fetchOne(
            "SELECT id, title, body, updated_at FROM pages
             WHERE user_id = ? AND archived_at IS NULL
               AND (id = 20 OR title = 'David — Shopping Preferences')
             ORDER BY CASE WHEN title = 'David — Shopping Preferences' THEN 0 ELSE 1 END, id
             LIMIT 1",
            [$userId]
        );
        $marker = $page === null ? 'missing' : ((string) $page['id'] . ':' . (string) $page['updated_at']);
        $prefs = new PreferenceService($this->db);
        if ($prefs->get($userId, 'pantry_upc_seeded') === $marker) {
            return;
        }
        if ($page !== null) {
            foreach (PantryCatalog::productsFromMarkdown((string) $page['body']) as $item) {
                $exists = $this->productByUpcVariants($userId, $item['upc']);
                if ($exists !== null) {
                    continue;
                }
                $category = PantryCatalog::categoryName($item['category'], $item['name']);
                $unit = $item['default_unit'] !== null ? $this->nullableUnit($item['default_unit']) : null;
                $this->db->execute(
                    'INSERT INTO pantry_products (user_id, upc, name, name_key, brand, category, default_size, default_unit, notes, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, \'vault\')',
                    [
                        $userId, $item['upc'], $item['name'], $this->nameKey($item['name']), $item['brand'],
                        $category, $item['default_size'], $unit, $item['notes'],
                    ]
                );
            }
        }
        $prefs->set($userId, 'pantry_upc_seeded', $marker);
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    private function addOne(int $userId, array $raw, string $by): array
    {
        $product = $this->matchProduct($userId, $raw, true);
        $location = $this->location((string) ($raw['location'] ?? 'pantry'));
        $unit = isset($raw['unit']) && $raw['unit'] !== '' && $raw['unit'] !== null
            ? $this->unit((string) $raw['unit'])
            : ($product['default_unit'] !== null ? (string) $product['default_unit'] : 'each');
        $quantity = $this->qty($raw['quantity'] ?? 1, 'quantity');
        $purchased = isset($raw['purchased_at']) && $raw['purchased_at'] !== ''
            ? $this->date((string) $raw['purchased_at'], 'purchased_at')
            : $this->clock->today();
        $explicit = array_key_exists('use_by', $raw) && $raw['use_by'] !== null && $raw['use_by'] !== '';
        $useBy = $explicit
            ? $this->date((string) $raw['use_by'], 'use_by')
            : PantryDates::initialUseBy($purchased, $this->locationDays($userId, $product, $location));
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO pantry_lots (
                user_id, product_id, quantity, unit, qty_level, location, purchased_at, store, order_ref, unit_price,
                use_by, use_by_overridden
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $userId, (int) $product['id'], $quantity, $unit, $this->level($raw['qty_level'] ?? null), $location, $purchased,
                $this->optionalText($raw['store'] ?? null, 80, 'store'),
                $this->optionalText($raw['order_ref'] ?? null, 80, 'order_ref'),
                $this->price($raw['unit_price'] ?? null),
                $useBy,
                $explicit ? 1 : 0,
            ]
        );
        $this->event($userId, $id, 'add', $quantity, $by, null);
        return $this->lot($userId, $id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function matchProduct(int $userId, array $input, bool $create): array
    {
        if (isset($input['product_id']) && $input['product_id'] !== '' && $input['product_id'] !== null) {
            return $this->requireProduct($userId, (int) $input['product_id']);
        }
        $upc = isset($input['upc']) && trim((string) $input['upc']) !== '' ? ProductLookup::requireUpc((string) $input['upc']) : null;
        if ($upc !== null) {
            $found = $this->productByUpcVariants($userId, $upc);
            if ($found !== null) {
                return $found;
            }
        }
        $name = isset($input['name']) ? trim((string) $input['name']) : '';
        if ($name !== '') {
            $exact = $this->db->fetchAll(
                'SELECT * FROM pantry_products WHERE user_id = ? AND name_key = ? ORDER BY id',
                [$userId, $this->nameKey($name)]
            );
            if (count($exact) === 1) {
                return $exact[0];
            }
            if (count($exact) > 1) {
                throw ValidationException::withFields(['name' => 'More than one product is named ' . $name . '.']);
            }
            $like = $this->db->fetchAll(
                'SELECT * FROM pantry_products WHERE user_id = ? AND name_key LIKE ? ORDER BY name_key, id LIMIT 8',
                [$userId, '%' . $this->like($this->nameKey($name)) . '%']
            );
            if (count($like) === 1) {
                return $like[0];
            }
            if (count($like) > 1) {
                $names = implode(', ', array_map(static fn (array $row): string => (string) $row['name'], $like));
                throw ValidationException::withFields(['name' => 'Name matches more than one product: ' . $names . '.']);
            }
        }
        if (!$create || $name === '') {
            throw ValidationException::withFields(['name' => $upc !== null
                ? 'No product with that UPC. Pass a name to add it.'
                : 'Name, UPC, or product_id is required.']);
        }
        return $this->requireProduct($userId, (int) $this->db->fetchScalar(
            'INSERT INTO pantry_products (user_id, upc, name, name_key, brand, category, default_size, default_unit, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $userId,
                $upc,
                $this->productName($name),
                $this->nameKey($name),
                $this->optionalText($input['brand'] ?? null, 120, 'brand'),
                PantryCatalog::categoryName(isset($input['category']) ? (string) $input['category'] : null, $name),
                $this->optionalText($input['default_size'] ?? $input['size'] ?? null, 80, 'default_size'),
                $this->nullableUnit($input['unit'] ?? $input['default_unit'] ?? null),
                $upc !== null ? 'lookup' : 'manual',
            ]
        ));
    }

    /**
     * @param array<string, mixed> $line
     * @return array{product: ?array<string, mixed>, reason: ?string}
     */
    private function matchOrderLine(int $userId, array $line): array
    {
        $upc = isset($line['upc']) && trim((string) $line['upc']) !== '' ? ProductLookup::requireUpc((string) $line['upc']) : null;
        $name = isset($line['name']) ? trim((string) $line['name']) : '';
        if ($upc !== null) {
            $found = $this->productByUpcVariants($userId, $upc);
            if ($found !== null) {
                return ['product' => $found, 'reason' => null];
            }
            if ($name === '') {
                return ['product' => null, 'reason' => 'No product with that UPC, and the line has no name.'];
            }
        }
        if ($name === '') {
            return ['product' => null, 'reason' => 'Each line needs a name or a UPC.'];
        }
        $exact = $this->db->fetchAll(
            'SELECT * FROM pantry_products WHERE user_id = ? AND name_key = ? ORDER BY id',
            [$userId, $this->nameKey($name)]
        );
        if (count($exact) > 1) {
            return ['product' => null, 'reason' => 'Name matches more than one product.'];
        }
        if (count($exact) === 1) {
            $product = $exact[0];
            if ($upc !== null && ($product['upc'] === null || $product['upc'] === '')) {
                $this->assertUpcFree($userId, $upc, (int) $product['id']);
                $this->db->execute('UPDATE pantry_products SET upc = ? WHERE id = ? AND user_id = ?', [$upc, (int) $product['id'], $userId]);
                $product['upc'] = $upc;
            }
            if ($upc !== null && $product['upc'] !== null && !in_array((string) $product['upc'], ProductLookup::variants($upc), true)) {
                return ['product' => null, 'reason' => 'That name already has a different UPC.'];
            }
            return ['product' => $product, 'reason' => null];
        }
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO pantry_products (user_id, upc, name, name_key, category, default_unit, source)
             VALUES (?, ?, ?, ?, ?, ?, \'order\') RETURNING id',
            [
                $userId, $upc, $this->productName($name), $this->nameKey($name),
                PantryCatalog::categoryName(isset($line['category']) ? (string) $line['category'] : null, $name),
                $this->nullableUnit($line['unit'] ?? null),
            ]
        );
        return ['product' => $this->requireProduct($userId, $id), 'reason' => null];
    }

    /**
     * @param array<string, mixed> $lot
     * @param list<mixed> $pieces
     * @return array<string, mixed>
     */
    private function splitPieces(int $userId, array $lot, array $pieces, string $by): array
    {
        $sum = 0.0;
        $parsed = [];
        foreach ($pieces as $i => $piece) {
            if (!is_array($piece)) {
                throw ValidationException::withFields(["pieces.{$i}" => 'Each piece must be an object.']);
            }
            $qty = (float) $this->qty($piece['quantity'] ?? null, 'quantity');
            $consume = filter_var($piece['consume'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $location = $consume ? (string) $lot['location'] : $this->location((string) ($piece['location'] ?? ''));
            $parsed[] = ['quantity' => $qty, 'consume' => $consume, 'location' => $location];
            $sum += $qty;
        }
        if (abs($sum - (float) $lot['quantity']) > 0.0005) {
            throw ValidationException::withFields(['pieces' => 'Pieces must add up to the lot quantity (' . $this->trimNum((float) $lot['quantity']) . ').']);
        }
        $product = $this->requireProduct($userId, (int) $lot['product_id']);
        $children = [];
        $consumed = 0.0;
        foreach ($parsed as $piece) {
            if ($piece['consume']) {
                $consumed += $piece['quantity'];
                continue;
            }
            $children[] = $this->insertChild($userId, $lot, $product, $piece['quantity'], $piece['location']);
        }
        $this->db->execute(
            "UPDATE pantry_lots SET quantity = 0, status = 'used', updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [(int) $lot['id'], $userId]
        );
        if ($consumed > 0) {
            $this->event($userId, (int) $lot['id'], 'consume', $this->trimNum($consumed), $by, null);
        }
        $this->event($userId, (int) $lot['id'], 'split', (string) $lot['quantity'], $by, null);
        foreach ($children as $child) {
            $this->event($userId, (int) $child['id'], 'add', $this->trimNum((float) $child['quantity']), $by, 'Split from lot ' . $lot['id']);
        }
        return [
            'parent' => $this->lot($userId, (int) $lot['id']),
            'lots' => array_map(fn (array $child): array => $this->lot($userId, (int) $child['id']), $children),
        ];
    }

    /**
     * @param array<string, mixed> $lot
     * @return array<string, mixed>
     */
    private function splitOff(int $userId, array $lot, float $quantity, string $location, string $by): array
    {
        $remain = round((float) $lot['quantity'] - $quantity, 3);
        $product = $this->requireProduct($userId, (int) $lot['product_id']);
        $child = $this->insertChild($userId, $lot, $product, $quantity, $location);
        $this->db->execute(
            "UPDATE pantry_lots SET quantity = ?, updated_at = (now() AT TIME ZONE 'UTC') WHERE id = ? AND user_id = ?",
            [$this->trimNum($remain), (int) $lot['id'], $userId]
        );
        $this->event($userId, (int) $lot['id'], 'split', $this->trimNum($quantity), $by, null);
        $this->event($userId, (int) $child['id'], 'add', $this->trimNum($quantity), $by, 'Split from lot ' . $lot['id']);
        return [
            'parent' => $this->lot($userId, (int) $lot['id']),
            'lots' => [$this->lot($userId, (int) $child['id'])],
        ];
    }

    /**
     * @param array<string, mixed> $lot
     * @param array<string, mixed> $product
     * @return array<string, mixed>
     */
    private function insertChild(int $userId, array $lot, array $product, float $quantity, string $location): array
    {
        $samePlace = $location === (string) $lot['location'];
        if ($samePlace) {
            $useBy = $lot['use_by'] !== null ? (string) $lot['use_by'] : null;
            $overridden = (int) $lot['use_by_overridden'] === 1;
            $opened = $lot['opened_at'] !== null ? (string) $lot['opened_at'] : null;
        } else {
            $next = PantryDates::afterMove(
                $location,
                (string) $lot['purchased_at'],
                $this->clock->today(),
                $lot['use_by'] !== null ? (string) $lot['use_by'] : null,
                false,
                true,
                $this->locationDays($userId, $product, $location)
            );
            $useBy = $next['use_by'];
            $overridden = false;
            $opened = null;
        }
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO pantry_lots (
                user_id, product_id, quantity, unit, qty_level, location, purchased_at, store, order_ref, unit_price,
                use_by, use_by_overridden, opened_at, parent_lot_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) RETURNING id',
            [
                $userId, (int) $lot['product_id'], $this->trimNum($quantity), $lot['unit'], $lot['qty_level'], $location,
                $lot['purchased_at'], $lot['store'], $lot['order_ref'], $lot['unit_price'],
                $useBy, $overridden ? 1 : 0, $opened, (int) $lot['id'],
            ]
        );
        return ['id' => $id, 'quantity' => $quantity];
    }

    /**
     * @param array<string, mixed> $lot
     * @return array<string, mixed>
     */
    private function moveWhole(int $userId, array $lot, string $location, bool $reset, string $by): array
    {
        $product = $this->requireProduct($userId, (int) $lot['product_id']);
        $next = PantryDates::afterMove(
            $location,
            (string) $lot['purchased_at'],
            $this->clock->today(),
            $lot['use_by'] !== null ? (string) $lot['use_by'] : null,
            (int) $lot['use_by_overridden'] === 1,
            $reset,
            $this->locationDays($userId, $product, $location)
        );
        $this->db->execute(
            'UPDATE pantry_lots SET location = ?, use_by = ?, use_by_overridden = ?, updated_at = (now() AT TIME ZONE \'UTC\')
             WHERE id = ? AND user_id = ?',
            [$location, $next['use_by'], $next['overridden'] ? 1 : 0, (int) $lot['id'], $userId]
        );
        $this->event($userId, (int) $lot['id'], 'move', (string) $lot['quantity'], $by, null);
        return $this->lot($userId, (int) $lot['id']);
    }

    /**
     * @param list<array<string, mixed>> $onHand
     * @param array<string, mixed> $ingredient
     */
    private function coverIngredient(array $onHand, array $ingredient): ?string
    {
        $need = $ingredient['quantity'];
        $unit = $ingredient['unit'];
        $have = 0.0;
        $soonest = null;
        $saw = false;
        foreach ($onHand as $lot) {
            $productMatch = $ingredient['product_id'] !== null && (int) $lot['id'] === (int) $ingredient['product_id'];
            $categoryMatch = $ingredient['product_id'] === null && (string) $lot['category'] === (string) $ingredient['category'];
            if (!$productMatch && !$categoryMatch) {
                continue;
            }
            if ($unit !== null && (string) $lot['unit'] !== $unit) {
                continue;
            }
            $saw = true;
            $have += (float) $lot['quantity'];
            if ($lot['use_by'] !== null && ($soonest === null || (string) $lot['use_by'] < $soonest)) {
                $soonest = (string) $lot['use_by'];
            }
        }
        if (!$saw) {
            return null;
        }
        if ($need !== null && $have + 0.0005 < (float) $need) {
            return null;
        }
        return $soonest ?? '';
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicRecipe(int $userId, array $row): array
    {
        $ingredients = $this->db->fetchAll(
            'SELECT i.product_id, i.category, i.quantity, i.unit, p.name
             FROM pantry_recipe_ingredients i
             LEFT JOIN pantry_products p ON p.id = i.product_id
             WHERE i.recipe_id = ? AND i.user_id = ?
             ORDER BY i.id',
            [(int) $row['id'], $userId]
        );
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'ingredients' => array_map(static function (array $ingredient): array {
                $label = $ingredient['name'] !== null ? (string) $ingredient['name'] : (string) $ingredient['category'];
                return [
                    'product_id' => $ingredient['product_id'] !== null ? (int) $ingredient['product_id'] : null,
                    'category' => $ingredient['category'] !== null ? (string) $ingredient['category'] : null,
                    'quantity' => $ingredient['quantity'] !== null ? (float) $ingredient['quantity'] : null,
                    'unit' => $ingredient['unit'] !== null ? (string) $ingredient['unit'] : null,
                    'label' => $label,
                ];
            }, $ingredients),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productByUpcVariants(int $userId, string $upc): ?array
    {
        $variants = ProductLookup::variants($upc);
        $marks = implode(', ', array_fill(0, count($variants), '?'));
        return $this->db->fetchOne(
            "SELECT * FROM pantry_products WHERE user_id = ? AND upc IN ({$marks}) ORDER BY id LIMIT 1",
            array_merge([$userId], $variants)
        );
    }

    /**
     * @param array<string, mixed> $product
     */
    private function locationDays(int $userId, array $product, string $location): ?int
    {
        $column = match ($location) {
            'fridge' => 'shelf_life_fridge_days',
            'freezer' => 'shelf_life_freezer_days',
            default => 'shelf_life_pantry_days',
        };
        $own = $product[$column] !== null ? (int) $product[$column] : null;
        $category = $this->categoryDays($userId, (string) $product['category']);
        $fallback = match ($location) {
            'fridge' => $category['fridge_days'],
            'freezer' => $category['freezer_days'],
            default => $category['pantry_days'],
        };
        return PantryDates::daysFor($location, $own, $fallback);
    }

    /**
     * @param array<string, mixed> $product
     */
    private function openedDays(int $userId, array $product): ?int
    {
        if ($product['opened_life_days'] !== null) {
            return (int) $product['opened_life_days'];
        }
        return $this->categoryDays($userId, (string) $product['category'])['opened_days'];
    }

    /**
     * @return array{pantry_days: ?int, fridge_days: ?int, freezer_days: ?int, opened_days: ?int}
     */
    private function categoryDays(int $userId, string $category): array
    {
        $row = $this->db->fetchOne(
            'SELECT pantry_days, fridge_days, freezer_days, opened_days FROM category_shelf_life WHERE user_id = ? AND category = ?',
            [$userId, $category]
        );
        if ($row === null) {
            $fallback = PantryCatalog::CATEGORIES[$category] ?? PantryCatalog::CATEGORIES['Other'];
            return [
                'pantry_days' => $fallback['pantry'],
                'fridge_days' => $fallback['fridge'],
                'freezer_days' => $fallback['freezer'],
                'opened_days' => $fallback['opened'],
            ];
        }
        return [
            'pantry_days' => $row['pantry_days'] !== null ? (int) $row['pantry_days'] : null,
            'fridge_days' => $row['fridge_days'] !== null ? (int) $row['fridge_days'] : null,
            'freezer_days' => $row['freezer_days'] !== null ? (int) $row['freezer_days'] : null,
            'opened_days' => $row['opened_days'] !== null ? (int) $row['opened_days'] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lot(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT l.*, p.name, p.brand, p.upc, p.category
             FROM pantry_lots l JOIN pantry_products p ON p.id = l.product_id
             WHERE l.id = ? AND l.user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Lot not found.');
        }
        return $this->publicLot($row, self::SOON_DAYS);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicLot(array $row, int $soonDays): array
    {
        $useBy = $row['use_by'] !== null ? (string) $row['use_by'] : null;
        $today = $this->clock->today();
        $freshness = 'unknown';
        $daysLeft = null;
        if ($useBy !== null) {
            $daysLeft = (int) (new \DateTimeImmutable($useBy))->diff(new \DateTimeImmutable($today))->format('%r%a');
            $daysLeft = -$daysLeft;
            if ($useBy < $today) {
                $freshness = 'expired';
            } elseif ($useBy <= PantryDates::addDays($today, $soonDays)) {
                $freshness = 'soon';
            } else {
                $freshness = 'fresh';
            }
        }
        return [
            'id' => (int) $row['id'],
            'product_id' => (int) $row['product_id'],
            'name' => (string) $row['name'],
            'brand' => $row['brand'] !== null ? (string) $row['brand'] : null,
            'upc' => $row['upc'] !== null ? (string) $row['upc'] : null,
            'category' => (string) $row['category'],
            'quantity' => $this->num($row['quantity']),
            'unit' => (string) $row['unit'],
            'qty_level' => $row['qty_level'] !== null ? (string) $row['qty_level'] : null,
            'location' => (string) $row['location'],
            'purchased_at' => (string) $row['purchased_at'],
            'store' => $row['store'] !== null ? (string) $row['store'] : null,
            'order_ref' => $row['order_ref'] !== null ? (string) $row['order_ref'] : null,
            'unit_price' => $row['unit_price'] !== null ? $this->num($row['unit_price']) : null,
            'use_by' => $useBy,
            'use_by_overridden' => (int) $row['use_by_overridden'] === 1,
            'opened_at' => $row['opened_at'] !== null ? (string) $row['opened_at'] : null,
            'status' => (string) $row['status'],
            'parent_lot_id' => $row['parent_lot_id'] !== null ? (int) $row['parent_lot_id'] : null,
            'freshness' => $freshness,
            'days_left' => $daysLeft,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicProduct(int $userId, array $row): array
    {
        $category = $this->categoryDays($userId, (string) $row['category']);
        return [
            'id' => (int) $row['id'],
            'upc' => $row['upc'] !== null ? (string) $row['upc'] : null,
            'name' => (string) $row['name'],
            'brand' => $row['brand'] !== null ? (string) $row['brand'] : null,
            'category' => (string) $row['category'],
            'default_size' => $row['default_size'] !== null ? (string) $row['default_size'] : null,
            'default_unit' => $row['default_unit'] !== null ? (string) $row['default_unit'] : null,
            'shelf_life_pantry_days' => $row['shelf_life_pantry_days'] !== null ? (int) $row['shelf_life_pantry_days'] : null,
            'shelf_life_fridge_days' => $row['shelf_life_fridge_days'] !== null ? (int) $row['shelf_life_fridge_days'] : null,
            'shelf_life_freezer_days' => $row['shelf_life_freezer_days'] !== null ? (int) $row['shelf_life_freezer_days'] : null,
            'opened_life_days' => $row['opened_life_days'] !== null ? (int) $row['opened_life_days'] : null,
            'effective_shelf_life' => [
                'pantry' => PantryDates::daysFor('pantry', $row['shelf_life_pantry_days'] !== null ? (int) $row['shelf_life_pantry_days'] : null, $category['pantry_days']),
                'fridge' => PantryDates::daysFor('fridge', $row['shelf_life_fridge_days'] !== null ? (int) $row['shelf_life_fridge_days'] : null, $category['fridge_days']),
                'freezer' => PantryDates::daysFor('freezer', $row['shelf_life_freezer_days'] !== null ? (int) $row['shelf_life_freezer_days'] : null, $category['freezer_days']),
                'opened' => $row['opened_life_days'] !== null ? (int) $row['opened_life_days'] : $category['opened_days'],
            ],
            'min_on_hand' => $row['min_on_hand'] !== null ? $this->num($row['min_on_hand']) : null,
            'image_url' => $row['image_url'] !== null ? (string) $row['image_url'] : null,
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'source' => (string) $row['source'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lockLot(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM pantry_lots WHERE id = ? AND user_id = ? FOR UPDATE', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Lot not found.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $lot
     */
    private function requireOnHand(array $lot): void
    {
        if ((string) $lot['status'] !== 'on_hand') {
            throw ValidationException::withFields(['lot_id' => 'That lot is ' . $lot['status'] . '.']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function requireProduct(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM pantry_products WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Product not found.');
        }
        return $row;
    }

    private function assertUpcFree(int $userId, ?string $upc, ?int $exceptId): void
    {
        if ($upc === null) {
            return;
        }
        $row = $this->productByUpcVariants($userId, $upc);
        if ($row !== null && ($exceptId === null || (int) $row['id'] !== $exceptId)) {
            throw ValidationException::withFields(['upc' => 'That UPC is already on ' . $row['name'] . '.']);
        }
    }

    private function event(int $userId, int $lotId, string $action, string $quantity, string $by, ?string $note): void
    {
        $this->db->execute(
            'INSERT INTO pantry_events (user_id, lot_id, action, quantity, at, by_name, note) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$userId, $lotId, $action, $quantity, $this->clock->utcString($this->clock->now()), $this->actor($by), $note]
        );
    }

    private function groceriesId(int $userId, ?int $requested): int
    {
        if ($requested !== null && $requested > 0) {
            $row = $this->db->fetchOne('SELECT id FROM shopping_lists WHERE id = ? AND user_id = ?', [$requested, $userId]);
            if ($row !== null) {
                return (int) $row['id'];
            }
            if ($requested !== 1) {
                throw new NotFoundException('Shopping list not found.');
            }
        }
        foreach ($this->shopping->lists($userId) as $list) {
            if (strcasecmp((string) $list['name'], 'Groceries') === 0) {
                return (int) $list['id'];
            }
        }
        $lists = $this->shopping->lists($userId);
        return (int) $lists[0]['id'];
    }

    private function lookup(): ProductLookup
    {
        return $this->lookup ?? new ProductLookup($this->db);
    }

    private function actor(string $by): string
    {
        $by = trim(preg_replace('/[^A-Za-z0-9 ._-]/', '', $by) ?? '');
        if ($by === '') {
            return 'david';
        }
        return mb_substr($by, 0, 64);
    }

    private function location(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, PantryDates::LOCATIONS, true)) {
            throw ValidationException::withFields(['location' => 'Location must be pantry, fridge, or freezer.']);
        }
        return $value;
    }

    private function level(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = strtolower(trim((string) $value));
        if (!in_array($value, PantryDates::LEVELS, true)) {
            throw ValidationException::withFields(['qty_level' => 'Level must be full, most, half, low, or nearly_empty.']);
        }
        return $value;
    }

    private function unit(string $value): string
    {
        $key = strtolower(trim($value));
        if (!isset(self::UNITS[$key])) {
            throw ValidationException::withFields(['unit' => 'Unknown unit.']);
        }
        return self::UNITS[$key];
    }

    private function nullableUnit(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return $this->unit((string) $value);
    }

    private function shoppingUnit(?string $unit): ?string
    {
        if ($unit === null || in_array($unit, ['each', 'head', 'bunch'], true)) {
            return null;
        }
        try {
            return ShoppingText::unit($unit);
        } catch (ValidationException) {
            return null;
        }
    }

    private function date(string $value, string $field): string
    {
        try {
            return Clock::normalizeDate(trim($value));
        } catch (\InvalidArgumentException) {
            throw ValidationException::withFields([$field => 'Date must be YYYY-MM-DD.']);
        }
    }

    private function qty(mixed $value, string $field): string
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields([$field => 'Quantity must be a number.']);
        }
        $n = round((float) $value, 3);
        if ($n <= 0 || $n > 100000) {
            throw ValidationException::withFields([$field => 'Quantity must be greater than 0 and at most 100000.']);
        }
        return $this->trimNum($n);
    }

    private function qtyAllowZero(mixed $value): string
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields(['quantity' => 'Quantity must be a number.']);
        }
        $n = round((float) $value, 3);
        if ($n < 0 || $n > 100000) {
            throw ValidationException::withFields(['quantity' => 'Quantity must be from 0 to 100000.']);
        }
        return $this->trimNum($n);
    }

    private function nullableQty(mixed $value, string $field, bool $allowZero): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw ValidationException::withFields([$field => 'Enter a number.']);
        }
        $n = round((float) $value, 3);
        if ($n < 0 || (!$allowZero && $n <= 0) || $n > 100000) {
            throw ValidationException::withFields([$field => 'Enter a number from 0 to 100000.']);
        }
        return $this->trimNum($n);
    }

    private function price(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            throw ValidationException::withFields(['unit_price' => 'Price must be a number.']);
        }
        $n = round((float) $value, 2);
        if ($n < 0 || $n > 100000) {
            throw ValidationException::withFields(['unit_price' => 'Price must be from 0 to 100000.']);
        }
        return number_format($n, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed>|null $existing
     */
    private function nullableDays(array $fields, string $key, ?array $existing): ?int
    {
        if (!array_key_exists($key, $fields)) {
            if ($existing === null || !array_key_exists($key, $existing) || $existing[$key] === null) {
                return null;
            }
            return (int) $existing[$key];
        }
        $value = $fields[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 3650 || (string) (int) $value !== (string) $value && !ctype_digit((string) $value)) {
            if (is_numeric($value) && (int) $value >= 0 && (int) $value <= 3650) {
                return (int) $value;
            }
            throw ValidationException::withFields([$key => 'Days must be a whole number from 0 to 3650.']);
        }
        return (int) $value;
    }

    private function keepDays(array $fields, string $key, mixed $current): ?int
    {
        if (!array_key_exists($key, $fields)) {
            return $current === null ? null : (int) $current;
        }
        $value = $fields[$key];
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value) || (int) $value < 0 || (int) $value > 3650) {
            throw ValidationException::withFields([$key => 'Days must be a whole number from 0 to 3650.']);
        }
        return (int) $value;
    }

    private function productName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw ValidationException::withFields(['name' => 'Enter a name.']);
        }
        if (mb_strlen($name) > 200) {
            throw ValidationException::withFields(['name' => 'Name must be 200 characters or fewer.']);
        }
        return $name;
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower($this->productName($name));
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
            throw ValidationException::withFields([$field => $field . ' must be ' . $max . ' characters or fewer.']);
        }
        return $text;
    }

    private function imageUrl(mixed $value): ?string
    {
        $text = $this->optionalText($value, 500, 'image_url');
        if ($text === null) {
            return null;
        }
        if (!str_starts_with($text, 'https://')) {
            throw ValidationException::withFields(['image_url' => 'Image URL must start with https://.']);
        }
        return $text;
    }

    private function nullableUpc(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return ProductLookup::requireUpc((string) $value);
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function idList(array $ids, string $field): array
    {
        if ($ids === []) {
            throw ValidationException::withFields([$field => 'Choose at least one lot.']);
        }
        if (count($ids) > self::MAX_BATCH) {
            throw ValidationException::withFields([$field => 'Choose up to ' . self::MAX_BATCH . ' lots.']);
        }
        $out = [];
        foreach ($ids as $id) {
            if (!is_numeric($id) || (int) $id < 1) {
                throw ValidationException::withFields([$field => 'Lot ids must be positive integers.']);
            }
            $out[] = (int) $id;
        }
        return array_values(array_unique($out));
    }

    /**
     * @param list<string> $allowed
     */
    private function optionalEnum(mixed $value, array $allowed, string $field): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, $allowed, true)) {
            throw ValidationException::withFields([$field => 'Unknown ' . $field . '.']);
        }
        return $value;
    }

    private function soonDays(mixed $value): int
    {
        $days = is_numeric($value) ? (int) $value : self::SOON_DAYS;
        if ($days < 1 || $days > 60) {
            throw ValidationException::withFields(['soon_days' => 'soon_days must be from 1 to 60.']);
        }
        return $days;
    }

    private function windowDays(mixed $value): int
    {
        if (!is_numeric($value)) {
            throw ValidationException::withFields(['days' => 'days must be a number.']);
        }
        $days = (int) $value;
        if ($days < 0 || $days > 365) {
            throw ValidationException::withFields(['days' => 'days must be from 0 to 365.']);
        }
        return $days;
    }

    private function like(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function num(mixed $value): float
    {
        return round((float) $value, 3);
    }

    private function trimNum(float $value): string
    {
        $text = number_format(round($value, 3), 3, '.', '');
        return rtrim(rtrim($text, '0'), '.') ?: '0';
    }
}
