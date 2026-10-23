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

    /** Bottom to top. Cook protein and base; pack the rest raw. */
    private const LAYER = [
        'sauce' => 'Sauce on the bottom',
        'base' => 'Base',
        'side' => 'Fibre and side',
        'vegetable' => 'Vegetables',
        'protein' => 'Protein on top',
        'aromatic' => 'Garnish',
        'spice' => 'Garnish',
    ];

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
        $matches = $this->recipeMatches($userId, $csv, $pickIds);
        $pickSet = array_fill_keys($pickIds, true);
        $grouped = [];
        foreach (array_merge(self::FILTER, self::SUPPORT) as $category) {
            $grouped[$category] = [];
        }
        $picks = [];
        foreach ($rows as $row) {
            $public = $this->publicComponent($row);
            $selected = isset($pickSet[$public['id']]);
            $public['swaps'] = [];
            if ($selected && !$public['on_hand']) {
                $others = array_values(array_filter(
                    $pickIds,
                    static fn (int $id): bool => $id !== $public['id']
                ));
                $public['swaps'] = $this->swapChoices($userId, $public['category'], $public['id'], $pickIds, $others);
            }
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
            'made_before' => $matches['made_before'],
            'matching_recipes' => $matches['matching_recipes'],
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
     * Read-only. Scales a recipe or the current picks and orders the jar steps.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function planPrep(int $userId, array $input): array
    {
        $servings = $this->servingCount($input['servings'] ?? null);
        $recipeId = isset($input['recipe_id']) && $input['recipe_id'] !== '' && $input['recipe_id'] !== null
            ? $this->intId($input['recipe_id'], 'recipe_id')
            : 0;
        $picks = isset($input['picks']) && is_array($input['picks']) ? $this->cleanIds($input['picks']) : [];
        if ($recipeId === 0 && $picks === []) {
            throw ValidationException::withFields(['picks' => 'Pick a combo or choose a recipe.']);
        }
        if ($recipeId > 0) {
            $built = $this->linesFromRecipe($userId, $recipeId, $servings);
        } else {
            $built = $this->linesFromPicks($userId, $picks, $servings);
        }
        $title = isset($input['name']) && trim((string) $input['name']) !== ''
            ? $this->componentName((string) $input['name'])
            : $built['title'];
        $label = $title . ' x' . $servings;
        if (mb_strlen($label) > 200) {
            throw ValidationException::withFields(['name' => 'Name must be 200 characters or fewer.']);
        }
        $keeps = $built['keeps_days'];
        [$lines, $shortages, $unlinked] = $this->finishLines($userId, $built['lines'], $built['pick_ids']);
        return [
            'title' => $title,
            'label' => $label,
            'servings' => $servings,
            'keeps_days' => $keeps,
            'use_by' => PantryDates::addDays($this->pantry->localToday(), $keeps),
            'protein_g' => $built['protein_g'],
            'meal_prep' => $built['meal_prep'],
            'recipe_id' => $recipeId > 0 ? $recipeId : null,
            'lines' => $lines,
            'steps' => $this->prepSteps($lines),
            'shortages' => $shortages,
            'unlinked' => $unlinked,
        ];
    }

    /**
     * Writes one fridge lot and deducts ingredients. A short pantry rolls the whole prep back.
     *
     * @param array<string, mixed> $input
     * @return array{plan: array<string, mixed>, lot: array<string, mixed>}
     */
    public function logPrep(int $userId, array $input, string $by): array
    {
        $plan = $this->planPrep($userId, $input);
        if ($plan['unlinked'] !== []) {
            throw ValidationException::withFields([
                'product_id' => 'Link a pantry product for ' . $plan['unlinked'][0] . ' before logging this prep.',
            ]);
        }
        if ($plan['shortages'] !== []) {
            $short = $plan['shortages'][0];
            throw ValidationException::withFields([
                'quantity' => 'Not enough ' . $short['name'] . ' on hand (have ' . $short['have'] . ', asked ' . $short['need'] . ').',
            ]);
        }
        return $this->db->transaction(function () use ($userId, $plan, $by): array {
            foreach ($plan['lines'] as $line) {
                $this->pantry->consume($userId, [
                    'product_id' => (int) $line['product_id'],
                    'quantity' => $line['quantity'],
                ], $by);
            }
            $added = $this->pantry->addItems($userId, [[
                'name' => $plan['label'],
                'quantity' => $plan['servings'],
                'unit' => 'serving',
                'location' => 'fridge',
                'category' => 'Other',
                'use_by' => $plan['use_by'],
            ]], $by);
            return ['plan' => $plan, 'lot' => $added['lots'][0]];
        });
    }

    /**
     * @param list<int> $pickIds
     * @return array{title: string, keeps_days: int, protein_g: ?float, meal_prep: bool, lines: list<array<string, mixed>>, pick_ids: list<int>}
     */
    private function linesFromPicks(int $userId, array $pickIds, int $servings): array
    {
        $picks = $this->pickedRows($userId, $pickIds);
        $units = $this->productUnits($userId, array_column($picks, 'product_id'));
        $lines = [];
        $names = [];
        foreach ($picks as $pick) {
            $productId = $pick['product_id'] !== null ? (int) $pick['product_id'] : null;
            $unit = $productId !== null ? ($units[$productId] ?? 'each') : 'each';
            $names[] = (string) $pick['name'];
            $lines[] = [
                'name' => (string) $pick['name'],
                'product_id' => $productId,
                'component_id' => (int) $pick['id'],
                'category' => (string) $pick['category'],
                'quantity' => (float) $servings,
                'unit' => $unit !== '' ? $unit : 'each',
            ];
        }
        return [
            'title' => implode(', ', $names),
            'keeps_days' => 3,
            'protein_g' => null,
            'meal_prep' => false,
            'lines' => $lines,
            'pick_ids' => $pickIds,
        ];
    }

    /**
     * @return array{title: string, keeps_days: int, protein_g: ?float, meal_prep: bool, lines: list<array<string, mixed>>, pick_ids: list<int>}
     */
    private function linesFromRecipe(int $userId, int $recipeId, int $servings): array
    {
        $recipe = $this->pantry->recipe($userId, $recipeId);
        $yield = $recipe['servings'] !== null ? (int) $recipe['servings'] : 1;
        $factor = $servings / $yield;
        $components = $this->db->fetchAll(
            'SELECT id, name, category, product_id FROM meal_components WHERE user_id = ? ORDER BY id',
            [$userId]
        );
        $productIds = [];
        foreach ($recipe['ingredients'] as $ingredient) {
            if ($ingredient['product_id'] !== null) {
                $productIds[] = (int) $ingredient['product_id'];
            }
        }
        $units = $this->productUnits($userId, $productIds);
        $lines = [];
        $pickIds = [];
        foreach ($recipe['ingredients'] as $ingredient) {
            $matched = $this->componentForIngredient($components, $ingredient);
            $productId = $ingredient['product_id'] !== null ? (int) $ingredient['product_id'] : null;
            $base = $ingredient['quantity'] !== null ? (float) $ingredient['quantity'] : 1.0;
            $qty = round($base * $factor, 3);
            if ($qty <= 0) {
                throw ValidationException::withFields([
                    'quantity' => 'Scaled quantity for ' . $ingredient['label'] . ' must be greater than 0.',
                ]);
            }
            $unit = $ingredient['unit'];
            if ($unit === null || $unit === '') {
                $unit = $productId !== null ? ($units[$productId] ?? 'each') : 'each';
            }
            if ($matched !== null && in_array((string) $matched['category'], self::FILTER, true)) {
                $pickIds[] = (int) $matched['id'];
            }
            $lines[] = [
                'name' => (string) $ingredient['label'],
                'product_id' => $productId,
                'component_id' => $matched !== null ? (int) $matched['id'] : null,
                'category' => $matched !== null ? (string) $matched['category'] : null,
                'quantity' => $qty,
                'unit' => $unit !== '' ? $unit : 'each',
            ];
        }
        return [
            'title' => (string) $recipe['name'],
            'keeps_days' => (int) $recipe['keeps_days'],
            'protein_g' => $recipe['protein_g'],
            'meal_prep' => (bool) $recipe['meal_prep'],
            'lines' => $lines,
            'pick_ids' => array_values(array_unique($pickIds)),
        ];
    }

    /**
     * @param list<array<string, mixed>> $components
     * @param array<string, mixed> $ingredient
     * @return array<string, mixed>|null
     */
    private function componentForIngredient(array $components, array $ingredient): ?array
    {
        $productId = $ingredient['product_id'];
        $label = $this->nameKey((string) $ingredient['label']);
        $named = null;
        foreach ($components as $component) {
            if ($productId !== null && $component['product_id'] !== null && (int) $component['product_id'] === (int) $productId) {
                return $component;
            }
            if ($named === null && $label !== '' && $this->nameKey((string) $component['name']) === $label) {
                $named = $component;
            }
        }
        return $named;
    }

    /**
     * @param list<mixed> $productIds
     * @return array<int, string>
     */
    private function productUnits(int $userId, array $productIds): array
    {
        $ids = [];
        foreach ($productIds as $id) {
            if ($id !== null && $id !== '' && (int) $id > 0) {
                $ids[(int) $id] = (int) $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT id, default_unit FROM pantry_products
             WHERE user_id = ? AND id::text IN (
                SELECT trim(x) FROM unnest(string_to_array(?, \',\')) AS t(x) WHERE trim(x) <> \'\'
             )',
            [$userId, implode(',', $ids)]
        );
        $units = [];
        foreach ($rows as $row) {
            $unit = $row['default_unit'] !== null ? (string) $row['default_unit'] : '';
            $units[(int) $row['id']] = $unit !== '' ? $unit : 'each';
        }
        return $units;
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @param list<int> $pickIds
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<string>}
     */
    private function finishLines(int $userId, array $lines, array $pickIds): array
    {
        $need = [];
        foreach ($lines as $line) {
            if ($line['product_id'] === null) {
                continue;
            }
            $id = (int) $line['product_id'];
            $need[$id] = ($need[$id] ?? 0) + (float) $line['quantity'];
        }
        $have = [];
        $names = [];
        foreach ($need as $id => $ignored) {
            $amount = $this->pantry->onHandAmount($userId, $id);
            $have[$id] = $amount['have'];
            $names[$id] = $amount['name'];
        }
        $shortages = [];
        foreach ($need as $id => $asked) {
            if ($have[$id] + 0.0005 < $asked) {
                $shortages[] = [
                    'product_id' => $id,
                    'name' => $names[$id],
                    'have' => $this->trimQty($have[$id]),
                    'need' => $this->trimQty($asked),
                ];
            }
        }
        $shortIds = [];
        foreach ($shortages as $short) {
            $shortIds[(int) $short['product_id']] = true;
        }
        $unlinked = [];
        foreach ($lines as $i => $line) {
            $id = $line['product_id'] !== null ? (int) $line['product_id'] : null;
            $qty = (float) $line['quantity'];
            $onHand = $id !== null && ($have[$id] ?? 0) > 0.0005;
            $lines[$i]['quantity'] = $this->trimQty($qty);
            $lines[$i]['on_hand'] = $onHand;
            $lines[$i]['have'] = $id !== null ? $this->trimQty($have[$id] ?? 0) : '0';
            $lines[$i]['need'] = $this->trimQty($qty);
            $lines[$i]['short'] = $id === null || isset($shortIds[$id]);
            $lines[$i]['swaps'] = [];
            if ($id === null) {
                $unlinked[] = (string) $line['name'];
            }
            if (!$onHand && $line['component_id'] !== null && $line['category'] !== null) {
                $componentId = (int) $line['component_id'];
                $others = array_values(array_filter(
                    $pickIds,
                    static fn (int $pick): bool => $pick !== $componentId
                ));
                $lines[$i]['swaps'] = $this->swapChoices($userId, (string) $line['category'], $componentId, $pickIds, $others);
            }
        }
        return [$lines, $shortages, $unlinked];
    }

    /**
     * @param list<array<string, mixed>> $lines
     * @return list<string>
     */
    private function prepSteps(array $lines): array
    {
        $by = [];
        $unknown = [];
        foreach ($lines as $line) {
            $category = $line['category'];
            if (!is_string($category) || !isset(self::LAYER[$category])) {
                $unknown[] = $line;
                continue;
            }
            $by[$category][] = $line;
        }
        $steps = [];
        $cook = [];
        foreach (['protein', 'base'] as $category) {
            foreach ($by[$category] ?? [] as $line) {
                $cook[] = $this->prepBit($line);
            }
        }
        if ($cook !== []) {
            $steps[] = 'Cook ahead: ' . implode(', ', $cook) . '.';
        }
        $raw = [];
        foreach (['sauce', 'side', 'vegetable', 'aromatic', 'spice'] as $category) {
            foreach ($by[$category] ?? [] as $line) {
                $raw[] = $this->prepBit($line);
            }
        }
        if ($raw !== []) {
            $steps[] = 'Pack raw: ' . implode(', ', $raw) . '.';
        }
        foreach (self::LAYER as $category => $label) {
            if (($by[$category] ?? []) === []) {
                continue;
            }
            $bits = array_map(fn (array $line): string => $this->prepBit($line), $by[$category]);
            $steps[] = $label . ': ' . implode(', ', $bits) . '.';
        }
        if ($unknown !== []) {
            $bits = array_map(fn (array $line): string => $this->prepBit($line), $unknown);
            $steps[] = 'Pack last: ' . implode(', ', $bits) . '.';
        }
        $steps[] = 'On the day, add hot water or microwave until hot, then stir from the bottom so the sauce coats the jar.';
        return $steps;
    }

    /**
     * @param array<string, mixed> $line
     */
    private function prepBit(array $line): string
    {
        $unit = trim((string) ($line['unit'] ?? ''));
        $amount = $unit !== '' ? $line['quantity'] . ' ' . $unit : (string) $line['quantity'];
        return $line['name'] . ' (' . $amount . ')';
    }

    /**
     * On-hand components in the same category that pair with every other pick.
     *
     * @param list<int> $excludeIds
     * @param list<int> $mustPair
     * @return list<array<string, mixed>>
     */
    private function swapChoices(int $userId, string $category, int $excludeId, array $excludeIds, array $mustPair): array
    {
        if (!in_array($category, array_merge(self::FILTER, self::SUPPORT), true)) {
            return [];
        }
        $skip = $excludeIds;
        $skip[] = $excludeId;
        $rows = $this->db->fetchAll(
            'SELECT c.id, c.name, c.category, c.product_id
             FROM meal_components c
             WHERE c.user_id = ?
               AND c.category = ?
               AND c.id <> ?
               AND NOT (c.id::text IN (
                   SELECT trim(x) FROM unnest(string_to_array(?, \',\')) AS t(x) WHERE trim(x) <> \'\'
               ))
               AND c.product_id IS NOT NULL AND EXISTS (
                   SELECT 1 FROM pantry_lots l
                   WHERE l.user_id = c.user_id AND l.product_id = c.product_id AND l.status = \'on_hand\'
                     AND (l.quantity > 0 OR (l.qty_level IS NOT NULL AND l.qty_level <> \'empty\'))
               )
               AND (
                   SELECT COUNT(*) FROM meal_components pk
                   WHERE pk.user_id = c.user_id
                     AND pk.id::text IN (
                         SELECT trim(x) FROM unnest(string_to_array(?, \',\')) AS u(x) WHERE trim(x) <> \'\'
                     )
                     AND NOT EXISTS (
                         SELECT 1 FROM meal_pairings mp
                         WHERE mp.user_id = c.user_id
                           AND mp.component_a = LEAST(c.id, pk.id)
                           AND mp.component_b = GREATEST(c.id, pk.id)
                     )
               ) = 0
             ORDER BY c.name, c.id',
            [$userId, $category, $excludeId, implode(',', $skip), implode(',', $mustPair)]
        );
        $swaps = [];
        foreach ($rows as $row) {
            $swaps[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'product_id' => (int) $row['product_id'],
                'on_hand' => true,
            ];
        }
        return $swaps;
    }

    /**
     * @param list<array{name: string, category: ?string, component_id: ?int, on_hand: bool}> $ingredients
     * @return list<array<string, mixed>>
     */
    private function attachSwaps(int $userId, array $ingredients): array
    {
        $filterIds = [];
        foreach ($ingredients as $item) {
            if ($item['component_id'] !== null && in_array($item['category'], self::FILTER, true)) {
                $filterIds[$item['component_id']] = $item['component_id'];
            }
        }
        $ids = array_values($filterIds);
        foreach ($ingredients as $i => $item) {
            $ingredients[$i]['swaps'] = [];
            if ($item['on_hand'] || $item['component_id'] === null || $item['category'] === null) {
                continue;
            }
            $others = array_values(array_filter(
                $ids,
                static fn (int $id): bool => $id !== $item['component_id']
            ));
            $ingredients[$i]['swaps'] = $this->swapChoices(
                $userId,
                $item['category'],
                $item['component_id'],
                $ids,
                $others
            );
        }
        return $ingredients;
    }

    private function servingCount(mixed $value): int
    {
        $whole = is_int($value)
            || (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1)
            || (is_float($value) && floor($value) === $value);
        $n = $whole ? (int) $value : 0;
        if (!$whole || $n < 1 || $n > 30) {
            throw ValidationException::withFields(['servings' => 'Servings must be a whole number from 1 to 30.']);
        }
        return $n;
    }

    private function trimQty(float $value): string
    {
        $text = number_format(round($value, 3), 3, '.', '');
        return rtrim(rtrim($text, '0'), '.') ?: '0';
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
     * One mapping query for both lists. made_before stays the exact filter-category set.
     * matching_recipes is every recipe whose filter-category components contain the picks.
     *
     * @param list<int> $pickIds
     * @return array{made_before: list<array<string, mixed>>, matching_recipes: list<array<string, mixed>>}
     */
    private function recipeMatches(int $userId, string $csv, array $pickIds): array
    {
        $rows = $this->db->fetchAll(
            <<<'SQL'
WITH picks AS (
    SELECT c.id
    FROM meal_components c
    WHERE c.user_id = ? AND c.id::text IN (
        SELECT trim(x) FROM unnest(string_to_array(?, ',')) AS t(x) WHERE trim(x) <> ''
    )
),
components AS (
    SELECT c.id, c.name, c.category, c.product_id,
           lower(regexp_replace(trim(c.name), '\s+', ' ', 'g')) AS name_key,
           CASE WHEN c.product_id IS NOT NULL AND EXISTS (
               SELECT 1 FROM pantry_lots l
               WHERE l.user_id = c.user_id AND l.product_id = c.product_id AND l.status = 'on_hand'
                 AND (l.quantity > 0 OR (l.qty_level IS NOT NULL AND l.qty_level <> 'empty'))
           ) THEN 1 ELSE 0 END AS on_hand
    FROM meal_components c
    WHERE c.user_id = ?
),
mapped AS (
    SELECT i.id AS ingredient_id,
           i.recipe_id,
           i.product_id,
           COALESCE(p.name, i.category) AS ingredient_name,
           match.component_id
    FROM pantry_recipe_ingredients i
    LEFT JOIN pantry_products p ON p.id = i.product_id AND p.user_id = i.user_id
    LEFT JOIN LATERAL (
        SELECT c.id AS component_id
        FROM components c
        WHERE (i.product_id IS NOT NULL AND c.product_id IS NOT NULL AND i.product_id = c.product_id)
           OR (p.id IS NOT NULL AND lower(regexp_replace(trim(p.name), '\s+', ' ', 'g')) = c.name_key)
           OR (i.product_id IS NULL AND i.category IS NOT NULL
               AND lower(regexp_replace(trim(i.category), '\s+', ' ', 'g')) = c.name_key)
        ORDER BY
            CASE
                WHEN i.product_id IS NOT NULL AND c.product_id IS NOT NULL AND i.product_id = c.product_id THEN 0
                WHEN p.id IS NOT NULL AND lower(regexp_replace(trim(p.name), '\s+', ' ', 'g')) = c.name_key THEN 1
                ELSE 2
            END,
            c.id
        LIMIT 1
    ) match ON true
    WHERE i.user_id = ?
),
filter_parts AS (
    SELECT m.recipe_id, m.component_id
    FROM mapped m
    JOIN components c ON c.id = m.component_id
    WHERE c.category IN ('protein', 'base', 'sauce', 'side')
),
hits AS (
    SELECT r.id, r.name, r.servings, r.keeps_days, r.meal_prep, r.protein_g
    FROM pantry_recipes r
    WHERE r.user_id = ?
      AND (
          (SELECT COUNT(*) FROM picks) = 0
          OR NOT EXISTS (
              SELECT 1 FROM picks pk
              WHERE NOT EXISTS (
                  SELECT 1 FROM filter_parts fp
                  WHERE fp.recipe_id = r.id AND fp.component_id = pk.id
              )
          )
      )
)
SELECT h.id, h.name, h.servings, h.keeps_days, h.meal_prep, h.protein_g, ing.ingredients
FROM hits h
LEFT JOIN LATERAL (
    SELECT json_agg(
               json_build_object(
                   'name', COALESCE(c.name, m.ingredient_name),
                   'category', c.category,
                   'component_id', m.component_id,
                   'on_hand', CASE
                       WHEN c.id IS NOT NULL THEN c.on_hand
                       WHEN m.product_id IS NOT NULL AND EXISTS (
                           SELECT 1 FROM pantry_lots l
                           WHERE l.user_id = ? AND l.product_id = m.product_id AND l.status = 'on_hand'
                             AND (l.quantity > 0 OR (l.qty_level IS NOT NULL AND l.qty_level <> 'empty'))
                       ) THEN 1
                       ELSE 0
                   END
               )
               ORDER BY m.ingredient_id
           ) AS ingredients
    FROM mapped m
    LEFT JOIN components c ON c.id = m.component_id
    WHERE m.recipe_id = h.id
) ing ON true
SQL,
            [$userId, $csv, $userId, $userId, $userId, $userId]
        );
        $pickSet = array_fill_keys($pickIds, true);
        $ranked = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $ingredients = $this->attachSwaps($userId, $this->alsoUses($row['ingredients'] ?? null));
            $filterIds = [];
            $seen = [];
            $onHand = 0;
            $uses = [];
            foreach ($ingredients as $item) {
                if ($item['on_hand']) {
                    $onHand++;
                }
                $category = $item['category'];
                $componentId = $item['component_id'];
                $support = $componentId === null || in_array($category, self::SUPPORT, true);
                if ($support) {
                    $uses[] = $item;
                }
                if ($componentId !== null && in_array($category, self::FILTER, true) && !isset($seen[$componentId])) {
                    $seen[$componentId] = true;
                    $filterIds[] = $componentId;
                }
            }
            $extra = 0;
            foreach ($filterIds as $componentId) {
                if (!isset($pickSet[$componentId])) {
                    $extra++;
                }
            }
            $exact = $pickIds !== [] && $extra === 0 && count($filterIds) === count($pickIds);
            $ranked[] = [
                'kind' => 'recipe',
                'id' => $id,
                'name' => (string) $row['name'],
                'url' => '/pantry?view=recipes&recipe=' . $id,
                'exact' => $exact,
                'picks' => $filterIds,
                'ingredients' => $ingredients,
                'also_uses' => $uses,
                'extra' => $extra,
                'on_hand_count' => $onHand,
                'servings' => $row['servings'] !== null && $row['servings'] !== '' ? (int) $row['servings'] : null,
                'keeps_days' => (int) $row['keeps_days'],
                'meal_prep' => $this->flag($row['meal_prep']),
                'protein_g' => $row['protein_g'] !== null && $row['protein_g'] !== '' ? (float) $row['protein_g'] : null,
            ];
        }
        $hasPicks = $pickIds !== [];
        usort($ranked, static function (array $a, array $b) use ($hasPicks): int {
            if ($hasPicks) {
                if ($a['exact'] !== $b['exact']) {
                    return $a['exact'] ? -1 : 1;
                }
                if ($a['extra'] !== $b['extra']) {
                    return $a['extra'] <=> $b['extra'];
                }
            }
            if ($a['on_hand_count'] !== $b['on_hand_count']) {
                return $b['on_hand_count'] <=> $a['on_hand_count'];
            }
            $name = strcmp($a['name'], $b['name']);
            if ($name !== 0) {
                return $name;
            }
            return $a['id'] <=> $b['id'];
        });
        $made = [];
        $matching = [];
        foreach ($ranked as $row) {
            if ($row['exact']) {
                $made[] = [
                    'kind' => 'recipe',
                    'id' => $row['id'],
                    'name' => $row['name'],
                    'url' => $row['url'],
                    'also_uses' => $row['also_uses'],
                    'picks' => $row['picks'],
                    'protein_g' => $row['protein_g'],
                    'meal_prep' => $row['meal_prep'],
                    'keeps_days' => $row['keeps_days'],
                ];
            }
            $matching[] = [
                'kind' => 'recipe',
                'id' => $row['id'],
                'name' => $row['name'],
                'url' => $row['url'],
                'exact' => $row['exact'],
                'picks' => $row['picks'],
                'ingredients' => $row['ingredients'],
                'protein_g' => $row['protein_g'],
                'meal_prep' => $row['meal_prep'],
                'keeps_days' => $row['keeps_days'],
                'servings' => $row['servings'],
            ];
        }
        usort($made, static function (array $a, array $b): int {
            $name = strcmp($a['name'], $b['name']);
            return $name !== 0 ? $name : $a['id'] <=> $b['id'];
        });
        return ['made_before' => $made, 'matching_recipes' => $matching];
    }

    /**
     * @return list<array{name: string, category: ?string, component_id: ?int, on_hand: bool}>
     */
    private function alsoUses(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($raw)) {
            return [];
        }
        $uses = [];
        foreach ($raw as $use) {
            if (!is_array($use)) {
                continue;
            }
            $name = trim((string) ($use['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $category = $use['category'] ?? null;
            $componentId = $use['component_id'] ?? null;
            $uses[] = [
                'name' => $name,
                'category' => is_string($category) && $category !== '' ? $category : null,
                'component_id' => is_numeric($componentId) && (int) $componentId > 0 ? (int) $componentId : null,
                'on_hand' => $this->flag($use['on_hand'] ?? false),
            ];
        }
        return $uses;
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
