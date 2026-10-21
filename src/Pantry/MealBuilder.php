<?php

declare(strict_types=1);

namespace Dashboard\Pantry;

use Dashboard\DB\Database;
use Dashboard\Deploy\DeployService;
use Dashboard\Settings\PreferenceService;
use Dashboard\Shopping\ShoppingService;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Components and pairwise pairings for the meal builder.
 * A meal is not stored. The filter asks which components pair with every pick.
 */
final class MealBuilder
{
    public const FILTER = ['protein', 'base', 'sauce', 'side'];

    public const SUPPORT = ['aromatic', 'vegetable', 'spice'];

    public const SOURCES = ['david', 'past_meal', 'research'];

    private const PREF_ON_HAND = 'meal_builder_on_hand_only';

    private const MAX_PER_CATEGORY = 2;

    public function __construct(
        private readonly Database $db,
        private readonly ShoppingService $shopping,
        private readonly PantryService $pantry,
    ) {}

    /**
     * A browser session has no token and can write while MCP updates are off.
     * A token write waits for the same Settings toggle as deploy_update.
     */
    public static function mcpWriteAllowed(bool $enabled, ?int $tokenId): bool
    {
        return DeployService::mcpRequestAllowed($enabled, $tokenId);
    }

    /**
     * @return array{components: list<array<string, mixed>>}
     */
    public function listComponents(int $userId, ?string $category, ?bool $onHand): array
    {
        $category = $category !== null && $category !== '' ? $this->category($category) : '';
        $flag = $onHand === null ? '' : ($onHand ? '1' : '0');
        $rows = $this->db->fetchAll(
            'SELECT c.id, c.name, c.category, c.product_id, c.notes, c.source, c.created_at,
                    ' . self::ON_HAND . ' AS on_hand
             FROM meal_components c
             WHERE c.user_id = ?
               AND (? = \'\' OR c.category = ?)
               AND (? = \'\' OR (' . self::ON_HAND . ')::text = ?)
             ORDER BY c.category, c.name, c.id',
            [$userId, $category, $category, $flag, $flag]
        );
        return ['components' => array_map(fn (array $row): array => $this->publicComponent($row), $rows)];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function addComponent(int $userId, array $fields): array
    {
        $name = $this->componentName((string) ($fields['name'] ?? ''));
        $category = $this->category((string) ($fields['category'] ?? ''));
        $source = $this->source($fields['source'] ?? null);
        $productId = $this->productId($userId, $fields, true);
        $notes = $this->optionalText($fields['notes'] ?? null, 2000, 'notes');
        $id = (int) $this->db->fetchScalar(
            'INSERT INTO meal_components (user_id, name, category, product_id, notes, source)
             VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$userId, $name, $category, $productId, $notes, $source]
        );
        return ['component' => $this->component($userId, $id)];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function updateComponent(int $userId, int $id, array $fields): array
    {
        $row = $this->requireComponent($userId, $id);
        $name = array_key_exists('name', $fields) ? $this->componentName((string) $fields['name']) : (string) $row['name'];
        $category = array_key_exists('category', $fields) ? $this->category((string) $fields['category']) : (string) $row['category'];
        $source = array_key_exists('source', $fields) ? $this->source($fields['source']) : (string) $row['source'];
        $productId = $this->productId($userId, $fields, false);
        if ($productId === false) {
            $productId = $row['product_id'] !== null ? (int) $row['product_id'] : null;
        }
        $notes = array_key_exists('notes', $fields) ? $this->optionalText($fields['notes'], 2000, 'notes') : ($row['notes'] !== null ? (string) $row['notes'] : null);
        $this->db->execute(
            'UPDATE meal_components SET name = ?, category = ?, product_id = ?, notes = ?, source = ? WHERE id = ? AND user_id = ?',
            [$name, $category, $productId, $notes, $source, $id, $userId]
        );
        return ['component' => $this->component($userId, $id)];
    }

    /**
     * @return array{removed: int}
     */
    public function removeComponent(int $userId, int $id): array
    {
        $this->requireComponent($userId, $id);
        $this->db->execute('DELETE FROM meal_components WHERE id = ? AND user_id = ?', [$id, $userId]);
        return ['removed' => $id];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public function addPairing(int $userId, array $fields): array
    {
        [$a, $b] = $this->pairEnds($userId, $fields);
        $existing = $this->db->fetchOne(
            'SELECT id, score, note, source FROM meal_pairings WHERE user_id = ? AND component_a = ? AND component_b = ?',
            [$userId, $a, $b]
        );
        $source = array_key_exists('source', $fields) ? $this->source($fields['source']) : ($existing['source'] ?? null);
        if ($source === null) {
            throw ValidationException::withFields(['source' => 'Source must be david, past_meal, or research.']);
        }
        $score = array_key_exists('score', $fields)
            ? $this->score($fields['score'])
            : ($existing !== null && $existing['score'] !== null ? (int) $existing['score'] : null);
        $note = array_key_exists('note', $fields)
            ? $this->optionalText($fields['note'], 500, 'note')
            : ($existing !== null && $existing['note'] !== null ? (string) $existing['note'] : null);
        if ($existing === null) {
            $id = (int) $this->db->fetchScalar(
                'INSERT INTO meal_pairings (user_id, component_a, component_b, score, note, source)
                 VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
                [$userId, $a, $b, $score, $note, $source]
            );
        } else {
            $id = (int) $existing['id'];
            $this->db->execute(
                'UPDATE meal_pairings SET score = ?, note = ?, source = ? WHERE id = ? AND user_id = ?',
                [$score, $note, $source, $id, $userId]
            );
        }
        return ['pairing' => $this->pairing($userId, $id)];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{removed: int}
     */
    public function removePairing(int $userId, array $fields): array
    {
        [$a, $b] = $this->pairEnds($userId, $fields);
        $row = $this->db->fetchOne(
            'SELECT id FROM meal_pairings WHERE user_id = ? AND component_a = ? AND component_b = ?',
            [$userId, $a, $b]
        );
        if ($row === null) {
            throw new NotFoundException('That pairing is not stored.');
        }
        $this->db->execute('DELETE FROM meal_pairings WHERE id = ? AND user_id = ?', [(int) $row['id'], $userId]);
        return ['removed' => (int) $row['id']];
    }

    /**
     * @return array{component_id: int, pairings: list<array<string, mixed>>}
     */
    public function listPairings(int $userId, int $componentId): array
    {
        $this->requireComponent($userId, $componentId);
        $rows = $this->db->fetchAll(
            'SELECT id, component_a, component_b, score, note, source, created_at
             FROM meal_pairings
             WHERE user_id = ? AND (component_a = ? OR component_b = ?)
             ORDER BY id',
            [$userId, $componentId, $componentId]
        );
        $pairings = [];
        foreach ($rows as $row) {
            $otherId = (int) $row['component_a'] === $componentId ? (int) $row['component_b'] : (int) $row['component_a'];
            $pairings[] = [
                'id' => (int) $row['id'],
                'component_a' => (int) $row['component_a'],
                'component_b' => (int) $row['component_b'],
                'other' => $this->component($userId, $otherId),
                'score' => $row['score'] !== null ? (int) $row['score'] : null,
                'note' => $row['note'] !== null ? (string) $row['note'] : null,
                'source' => (string) $row['source'],
                'created_at' => (string) $row['created_at'],
            ];
        }
        return ['component_id' => $componentId, 'pairings' => $pairings];
    }

    /**
     * @param list<int> $pickIds
     * @return array<string, mixed>
     */
    public function suggest(int $userId, array $pickIds, ?bool $onHandOnly, bool $remember): array
    {
        $pickIds = $this->cleanIds($pickIds);
        if ($remember && $onHandOnly !== null) {
            (new PreferenceService($this->db))->set($userId, self::PREF_ON_HAND, $onHandOnly ? '1' : '0');
        }
        $only = $onHandOnly ?? ((new PreferenceService($this->db))->get($userId, self::PREF_ON_HAND, '0') === '1');
        $csv = implode(',', $pickIds);
        if ($pickIds !== []) {
            $found = $this->db->fetchAll(
                'SELECT id, category FROM meal_components WHERE user_id = ? AND id::text IN (
                    SELECT trim(x) FROM unnest(string_to_array(?, \',\')) AS t(x) WHERE trim(x) <> \'\'
                 )',
                [$userId, $csv]
            );
            if (count($found) !== count($pickIds)) {
                throw ValidationException::withFields(['picks' => 'Unknown component.']);
            }
            $per = [];
            foreach ($found as $row) {
                $category = (string) $row['category'];
                if (!in_array($category, self::FILTER, true)) {
                    throw ValidationException::withFields(['picks' => 'Picks must be protein, base, sauce, or side.']);
                }
                $per[$category] = ($per[$category] ?? 0) + 1;
                if ($per[$category] > self::MAX_PER_CATEGORY) {
                    throw ValidationException::withFields(['picks' => 'Pick up to 2 in ' . $category . '.']);
                }
            }
            $this->requirePicksPair($userId, $pickIds);
        }
        $sql = <<<'SQL'
WITH picks AS (
    SELECT c.id FROM meal_components c
    WHERE c.user_id = ? AND c.id::text IN (
        SELECT trim(x) FROM unnest(string_to_array(?, ',')) AS t(x) WHERE trim(x) <> ''
    )
),
pick_n AS (SELECT COUNT(*)::int AS n FROM picks)
SELECT c.id, c.name, c.category, c.product_id, c.notes, c.source, c.created_at,
       CASE WHEN c.product_id IS NOT NULL AND EXISTS (
           SELECT 1 FROM pantry_lots l
           WHERE l.user_id = c.user_id AND l.product_id = c.product_id AND l.status = 'on_hand'
             AND (l.quantity > 0 OR (l.qty_level IS NOT NULL AND l.qty_level <> 'empty'))
       ) THEN 1 ELSE 0 END AS on_hand,
       (
         SELECT COUNT(*) FROM picks pk
         JOIN meal_pairings mp
           ON mp.user_id = c.user_id
          AND mp.component_a = LEAST(c.id, pk.id)
          AND mp.component_b = GREATEST(c.id, pk.id)
         WHERE pk.id <> c.id
       ) AS pair_hits,
       (
         SELECT COALESCE(SUM(mp.score), 0) FROM picks pk
         JOIN meal_pairings mp
           ON mp.user_id = c.user_id
          AND mp.component_a = LEAST(c.id, pk.id)
          AND mp.component_b = GREATEST(c.id, pk.id)
         WHERE pk.id <> c.id
       ) AS pair_score
FROM meal_components c
CROSS JOIN pick_n
WHERE c.user_id = ?
AND (
    (
        c.category IN ('protein', 'base', 'sauce', 'side')
        AND (
            SELECT COUNT(*) FROM picks pk
            WHERE pk.id <> c.id
              AND NOT EXISTS (
                SELECT 1 FROM meal_pairings mp
                WHERE mp.user_id = c.user_id
                  AND mp.component_a = LEAST(c.id, pk.id)
                  AND mp.component_b = GREATEST(c.id, pk.id)
              )
        ) = 0
    )
    OR (
        c.category IN ('aromatic', 'vegetable', 'spice')
        AND (
            pick_n.n = 0
            OR EXISTS (
                SELECT 1 FROM picks pk
                JOIN meal_pairings mp
                  ON mp.user_id = c.user_id
                 AND mp.component_a = LEAST(c.id, pk.id)
                 AND mp.component_b = GREATEST(c.id, pk.id)
                WHERE pk.id <> c.id
            )
        )
    )
)
ORDER BY c.category, pair_hits DESC, pair_score DESC, c.name, c.id
SQL;
        $rows = $this->db->fetchAll($sql, [$userId, $csv, $userId]);
        $pickSet = array_fill_keys($pickIds, true);
        $grouped = [];
        foreach (array_merge(self::FILTER, self::SUPPORT) as $category) {
            $grouped[$category] = [];
        }
        $picks = [];
        foreach ($rows as $row) {
            $public = $this->publicComponent($row);
            $selected = isset($pickSet[$public['id']]);
            if ($only && !$public['on_hand'] && !$selected) {
                continue;
            }
            $grouped[$public['category']][] = $public;
            if ($selected) {
                $picks[] = $public;
            }
        }
        return [
            'picks' => $picks,
            'categories' => [
                'protein' => $grouped['protein'],
                'base' => $grouped['base'],
                'sauce' => $grouped['sauce'],
                'side' => $grouped['side'],
            ],
            'supporting' => [
                'aromatic' => $grouped['aromatic'],
                'vegetable' => $grouped['vegetable'],
                'spice' => $grouped['spice'],
            ],
            'made_before' => $pickIds === [] ? [] : $this->madeBefore($userId, $csv),
            'on_hand_only' => $only,
        ];
    }

    /**
     * @param list<int> $pickIds
     * @return array<string, mixed>
     */
    public function saveRecipe(int $userId, string $name, array $pickIds): array
    {
        $picks = $this->pickedRows($userId, $pickIds);
        $ingredients = [];
        foreach ($picks as $pick) {
            $productId = $pick['product_id'] !== null ? (int) $pick['product_id'] : $this->ensureProduct($userId, (string) $pick['name']);
            $ingredients[] = ['product_id' => $productId];
        }
        return ['recipe' => $this->pantry->upsertRecipe($userId, [
            'name' => $name,
            'ingredients' => $ingredients,
        ])];
    }

    /**
     * @param list<int> $pickIds
     * @return array{added: list<array<string, mixed>>}
     */
    public function addMissing(int $userId, array $pickIds, string $by): array
    {
        $picks = $this->pickedRows($userId, $pickIds);
        $names = [];
        foreach ($picks as $pick) {
            if (!$this->flag($pick['on_hand'])) {
                $names[] = ['name' => (string) $pick['name']];
            }
        }
        if ($names === []) {
            return ['added' => []];
        }
        $listId = $this->groceriesId($userId);
        $addedBy = $by === 'agent' ? 'agent' : 'user';
        return ['added' => $this->shopping->addItems($userId, $listId, $names, $addedBy)];
    }

    /**
     * Active lot: on hand, with quantity left or a level other than empty.
     */
    private const ON_HAND = "CASE WHEN c.product_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM pantry_lots l
        WHERE l.user_id = c.user_id AND l.product_id = c.product_id AND l.status = 'on_hand'
          AND (l.quantity > 0 OR (l.qty_level IS NOT NULL AND l.qty_level <> 'empty'))
    ) THEN 1 ELSE 0 END";

    /**
     * @return list<array<string, mixed>>
     */
    private function madeBefore(int $userId, string $csv): array
    {
        $rows = $this->db->fetchAll(
            'WITH picks AS (
                SELECT c.id, c.product_id, lower(regexp_replace(trim(c.name), \'\\s+\', \' \', \'g\')) AS name_key
                FROM meal_components c
                WHERE c.user_id = ? AND c.id::text IN (
                    SELECT trim(x) FROM unnest(string_to_array(?, \',\')) AS t(x) WHERE trim(x) <> \'\'
                )
             ),
             pick_n AS (SELECT COUNT(*)::int AS n FROM picks)
             SELECT r.id, r.name
             FROM pantry_recipes r
             CROSS JOIN pick_n
             WHERE r.user_id = ?
               AND pick_n.n > 0
               AND (SELECT COUNT(*) FROM pantry_recipe_ingredients i WHERE i.recipe_id = r.id AND i.user_id = r.user_id) = pick_n.n
               AND NOT EXISTS (
                    SELECT 1 FROM picks pk
                    WHERE NOT EXISTS (
                        SELECT 1
                        FROM pantry_recipe_ingredients i
                        LEFT JOIN pantry_products p ON p.id = i.product_id AND p.user_id = r.user_id
                        WHERE i.recipe_id = r.id AND i.user_id = r.user_id
                          AND (
                            (i.product_id IS NOT NULL AND pk.product_id IS NOT NULL AND i.product_id = pk.product_id)
                            OR (p.id IS NOT NULL AND lower(regexp_replace(trim(p.name), \'\\s+\', \' \', \'g\')) = pk.name_key)
                            OR (i.product_id IS NULL AND i.category IS NOT NULL AND lower(i.category) = pk.name_key)
                          )
                    )
               )
               AND NOT EXISTS (
                    SELECT 1
                    FROM pantry_recipe_ingredients i
                    LEFT JOIN pantry_products p ON p.id = i.product_id AND p.user_id = r.user_id
                    WHERE i.recipe_id = r.id AND i.user_id = r.user_id
                      AND NOT EXISTS (
                        SELECT 1 FROM picks pk
                        WHERE (
                            (i.product_id IS NOT NULL AND pk.product_id IS NOT NULL AND i.product_id = pk.product_id)
                            OR (p.id IS NOT NULL AND lower(regexp_replace(trim(p.name), \'\\s+\', \' \', \'g\')) = pk.name_key)
                            OR (i.product_id IS NULL AND i.category IS NOT NULL AND lower(i.category) = pk.name_key)
                        )
                      )
               )
             ORDER BY r.name, r.id',
            [$userId, $csv, $userId]
        );
        return array_map(static function (array $row): array {
            $id = (int) $row['id'];
            return [
                'kind' => 'recipe',
                'id' => $id,
                'name' => (string) $row['name'],
                'url' => '/pantry?view=recipes&recipe=' . $id,
            ];
        }, $rows);
    }

    /**
     * @param list<int> $pickIds
     */
    private function requirePicksPair(int $userId, array $pickIds): void
    {
        $n = count($pickIds);
        if ($n < 2) {
            return;
        }
        $csv = implode(',', $pickIds);
        $links = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM meal_pairings mp
             WHERE mp.user_id = ?
               AND mp.component_a::text IN (SELECT trim(x) FROM unnest(string_to_array(?, \',\')) AS t(x) WHERE trim(x) <> \'\')
               AND mp.component_b::text IN (SELECT trim(x) FROM unnest(string_to_array(?, \',\')) AS u(x) WHERE trim(x) <> \'\')',
            [$userId, $csv, $csv]
        );
        $need = $n * ($n - 1) / 2;
        if ($links < $need) {
            throw ValidationException::withFields(['picks' => 'Those picks do not all pair.']);
        }
    }

    /**
     * @param list<int> $pickIds
     * @return list<array<string, mixed>>
     */
    private function pickedRows(int $userId, array $pickIds): array
    {
        $pickIds = $this->cleanIds($pickIds);
        if ($pickIds === []) {
            throw ValidationException::withFields(['picks' => 'Pick at least one component.']);
        }
        $board = $this->suggest($userId, $pickIds, false, false);
        if (count($board['picks']) !== count($pickIds)) {
            throw ValidationException::withFields(['picks' => 'Unknown component.']);
        }
        return $board['picks'];
    }

    private function ensureProduct(int $userId, string $name): int
    {
        $key = $this->nameKey($name);
        $rows = $this->db->fetchAll(
            'SELECT id FROM pantry_products WHERE user_id = ? AND name_key = ? ORDER BY id',
            [$userId, $key]
        );
        if (count($rows) > 1) {
            throw ValidationException::withFields(['name' => 'More than one product is named ' . $name . '.']);
        }
        if (count($rows) === 1) {
            return (int) $rows[0]['id'];
        }
        return (int) $this->db->fetchScalar(
            'INSERT INTO pantry_products (user_id, name, name_key, category, source)
             VALUES (?, ?, ?, \'Other\', \'manual\') RETURNING id',
            [$userId, $name, $key]
        );
    }

    private function groceriesId(int $userId): int
    {
        foreach ($this->shopping->lists($userId) as $list) {
            if (strcasecmp((string) $list['name'], 'Groceries') === 0) {
                return (int) $list['id'];
            }
        }
        $lists = $this->shopping->lists($userId);
        return (int) $lists[0]['id'];
    }

    /**
     * @param array<string, mixed> $fields
     * @return array{0: int, 1: int}
     */
    private function pairEnds(int $userId, array $fields): array
    {
        $a = $this->endId($userId, $fields, 'a');
        $b = $this->endId($userId, $fields, 'b');
        if ($a === $b) {
            throw ValidationException::withFields(['component_b' => 'A component does not pair with itself.']);
        }
        return $a < $b ? [$a, $b] : [$b, $a];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function endId(int $userId, array $fields, string $side): int
    {
        $idKey = 'component_' . $side . '_id';
        $nameKey = 'component_' . $side;
        if (isset($fields[$idKey]) && $fields[$idKey] !== '' && $fields[$idKey] !== null) {
            $id = $this->intId($fields[$idKey], $idKey);
            $this->requireComponent($userId, $id);
            return $id;
        }
        $name = trim((string) ($fields[$nameKey] ?? ''));
        if ($name === '') {
            throw ValidationException::withFields([$idKey => 'Set a component id or name.']);
        }
        $rows = $this->db->fetchAll(
            'SELECT id FROM meal_components WHERE user_id = ? AND lower(name) = ? ORDER BY id',
            [$userId, $this->nameKey($name)]
        );
        if ($rows === []) {
            throw ValidationException::withFields([$nameKey => 'No component named ' . $name . '.']);
        }
        if (count($rows) > 1) {
            throw ValidationException::withFields([$nameKey => 'More than one component is named ' . $name . '.']);
        }
        return (int) $rows[0]['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function component(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT c.id, c.name, c.category, c.product_id, c.notes, c.source, c.created_at,
                    ' . self::ON_HAND . ' AS on_hand
             FROM meal_components c WHERE c.id = ? AND c.user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('Component not found.');
        }
        return $this->publicComponent($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireComponent(int $userId, int $id): array
    {
        $row = $this->db->fetchOne('SELECT * FROM meal_components WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw new NotFoundException('Component not found.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function pairing(int $userId, int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT id, component_a, component_b, score, note, source, created_at FROM meal_pairings WHERE id = ? AND user_id = ?',
            [$id, $userId]
        );
        if ($row === null) {
            throw new NotFoundException('That pairing is not stored.');
        }
        return [
            'id' => (int) $row['id'],
            'component_a' => (int) $row['component_a'],
            'component_b' => (int) $row['component_b'],
            'score' => $row['score'] !== null ? (int) $row['score'] : null,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'source' => (string) $row['source'],
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function publicComponent(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'product_id' => $row['product_id'] !== null ? (int) $row['product_id'] : null,
            'notes' => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
            'source' => (string) $row['source'],
            'on_hand' => $this->flag($row['on_hand'] ?? 0),
            'pair_hits' => isset($row['pair_hits']) ? (int) $row['pair_hits'] : 0,
            'pair_score' => isset($row['pair_score']) ? (int) $row['pair_score'] : 0,
            'created_at' => (string) $row['created_at'],
        ];
    }

    /**
     * Absent product_id on create is null. Absent on update means leave it.
     * A present null clears the link.
     *
     * @param array<string, mixed> $fields
     */
    private function productId(int $userId, array $fields, bool $creating): int|false|null
    {
        if (!array_key_exists('product_id', $fields)) {
            return $creating ? null : false;
        }
        $value = $fields['product_id'];
        if ($value === null || $value === '') {
            return null;
        }
        $id = $this->intId($value, 'product_id');
        $row = $this->db->fetchOne('SELECT id FROM pantry_products WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($row === null) {
            throw ValidationException::withFields(['product_id' => 'Product not found.']);
        }
        return $id;
    }

    private function category(string $value): string
    {
        $value = strtolower(trim($value));
        if (!in_array($value, array_merge(self::FILTER, self::SUPPORT), true)) {
            throw ValidationException::withFields(['category' => 'Category must be protein, base, sauce, side, aromatic, vegetable, or spice.']);
        }
        return $value;
    }

    private function source(mixed $value): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, self::SOURCES, true)) {
            throw ValidationException::withFields(['source' => 'Source must be david, past_meal, or research.']);
        }
        return $value;
    }

    private function score(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $whole = is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) || (is_float($value) && floor($value) === $value);
        $n = $whole ? (int) $value : 0;
        if (!$whole || $n < 1 || $n > 5) {
            throw ValidationException::withFields(['score' => 'Score must be a whole number from 1 to 5, or empty.']);
        }
        return $n;
    }

    private function componentName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '') {
            throw ValidationException::withFields(['name' => 'Enter a name.']);
        }
        if (mb_strlen($name) > 160) {
            throw ValidationException::withFields(['name' => 'Name must be 160 characters or fewer.']);
        }
        return $name;
    }

    private function nameKey(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
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

    private function intId(mixed $value, string $field): int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if ((is_string($value) || is_float($value)) && preg_match('/^[0-9]+$/', (string) $value) === 1 && (int) $value > 0) {
            return (int) $value;
        }
        throw ValidationException::withFields([$field => 'Enter a component id.']);
    }

    /**
     * @param list<mixed> $ids
     * @return list<int>
     */
    private function cleanIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if ($id === null || $id === '') {
                continue;
            }
            $n = $this->intId($id, 'picks');
            $out[$n] = $n;
        }
        return array_values($out);
    }

    private function flag(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}
