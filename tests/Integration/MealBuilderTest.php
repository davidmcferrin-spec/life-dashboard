<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Pantry\MealBuilder;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class MealBuilderTest extends PgsqlTestCase
{
    private Services $svc;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('david', 'd@x', 'h')");
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-09-25 15:00:00', new DateTimeZone('UTC'))));
    }

    public function testPairingIsStoredWithTheLowerIdFirst(): void
    {
        $beef = $this->component('Ground beef', 'protein');
        $rice = $this->component('Rice', 'base');
        $high = max($beef['id'], $rice['id']);
        $low = min($beef['id'], $rice['id']);

        $pairing = $this->svc->meals->addPairing($this->user, [
            'component_a_id' => $rice['id'],
            'component_b' => 'Ground beef',
            'score' => 4,
            'note' => 'weeknight',
            'source' => 'research',
        ])['pairing'];
        self::assertSame($low, $pairing['component_a']);
        self::assertSame($high, $pairing['component_b']);
        self::assertSame(4, $pairing['score']);

        $again = $this->svc->meals->addPairing($this->user, [
            'component_a' => 'ground beef',
            'component_b_id' => $rice['id'],
            'score' => 2,
            'source' => 'david',
        ])['pairing'];
        self::assertSame($pairing['id'], $again['id']);
        self::assertSame(2, $again['score']);
        self::assertSame('david', $again['source']);
        self::assertSame(1, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM meal_pairings WHERE user_id = ?', [$this->user]));

        $listed = $this->svc->meals->listPairings($this->user, $beef['id']);
        self::assertSame($rice['id'], $listed['pairings'][0]['other']['id']);

        try {
            $this->svc->meals->addPairing($this->user, [
                'component_a_id' => $beef['id'],
                'component_b_id' => $beef['id'],
                'source' => 'david',
            ]);
            self::fail('A component paired with itself.');
        } catch (ValidationException $e) {
            self::assertSame('A component does not pair with itself.', $e->fields()['component_b']);
        }
    }

    public function testSuggestKeepsOnlyComponentsThatPairWithEveryPick(): void
    {
        $ids = $this->kitchen();
        $open = $this->svc->meals->suggest($this->user, [], null, false);
        self::assertSame(
            ['Ground beef', 'Salmon', 'Sausage', 'Turkey'],
            $this->names($open['categories']['protein'])
        );
        self::assertContains('Onion', $this->names($open['supporting']['aromatic']));

        $beef = $this->svc->meals->suggest($this->user, [$ids['beef']], false, false);
        self::assertSame(['Rice', 'Noodles', 'Potatoes'], $this->names($beef['categories']['base']));
        self::assertNotContains('Mixed greens', $this->names($beef['categories']['base']));
        self::assertContains('Soy sauce', $this->names($beef['categories']['sauce']));
        self::assertContains('Ranch', $this->names($beef['categories']['sauce']));

        $both = $this->svc->meals->suggest($this->user, [$ids['rice'], $ids['beef']], false, false);
        self::assertSame(['Soy sauce'], $this->names($both['categories']['sauce']));
        self::assertSame(['Olives'], $this->names($both['categories']['side']));
        self::assertSame(['Onion', 'Garlic'], $this->names($both['supporting']['aromatic']));
        self::assertSame(2, $both['supporting']['aromatic'][0]['pair_hits']);
        self::assertSame(1, $both['supporting']['aromatic'][1]['pair_hits']);
        self::assertSame([], $both['supporting']['vegetable']);
        self::assertSame([], $this->names($both['made_before']));

        $two = $this->svc->meals->suggest($this->user, [$ids['beef'], $ids['turkey']], false, false);
        self::assertSame(['Rice'], $this->names($two['categories']['base']));
        self::assertContains('Ground beef', $this->names($two['categories']['protein']));
        self::assertContains('Turkey', $this->names($two['categories']['protein']));

        try {
            $this->svc->meals->suggest($this->user, [$ids['beef'], $ids['turkey'], $ids['sausage']], false, false);
            self::fail('Three proteins were accepted.');
        } catch (ValidationException $e) {
            self::assertSame('Pick up to 2 in protein.', $e->fields()['picks']);
        }

        try {
            $this->svc->meals->suggest($this->user, [$ids['beef'], $ids['salmon']], false, false);
            self::fail('Unpaired picks were accepted.');
        } catch (ValidationException $e) {
            self::assertSame('Those picks do not all pair.', $e->fields()['picks']);
        }
    }

    public function testOnHandFollowsActiveLotsAndTheRememberedFilter(): void
    {
        $beefProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Ground beef', 'category' => 'Meat']);
        $ranchProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Ranch', 'category' => 'Other']);
        $beefLot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $beefProduct['id'],
            'quantity' => 1,
            'location' => 'fridge',
        ]], 'david')['lots'][0];
        $ranchLot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $ranchProduct['id'],
            'quantity' => 1,
            'qty_level' => 'half',
            'location' => 'fridge',
        ]], 'david')['lots'][0];
        $beef = $this->component('Ground beef', 'protein', $beefProduct['id']);
        $ranch = $this->component('Ranch', 'sauce', $ranchProduct['id']);
        $rice = $this->component('Rice', 'base');
        self::assertTrue($beef['on_hand']);
        self::assertTrue($ranch['on_hand']);
        self::assertFalse($rice['on_hand']);

        $only = $this->svc->meals->listComponents($this->user, null, true);
        self::assertSame(['Ground beef', 'Ranch'], $this->names($only['components']));

        $this->svc->pantry->consume($this->user, ['lot_id' => $beefLot['id'], 'quantity' => 1], 'david');
        $this->svc->pantry->toss($this->user, [$ranchLot['id']], 'david');
        $after = $this->svc->meals->listComponents($this->user, null, false);
        self::assertEqualsCanonicalizing(['Ground beef', 'Ranch', 'Rice'], $this->names($after['components']));

        $kept = $this->svc->meals->suggest($this->user, [$rice['id']], true, true);
        self::assertTrue($kept['on_hand_only']);
        self::assertSame(['Rice'], $this->names($kept['categories']['base']));
        self::assertSame([], $kept['categories']['protein']);

        $remembered = $this->svc->meals->suggest($this->user, [], null, false);
        self::assertTrue($remembered['on_hand_only']);
        self::assertSame([], $remembered['categories']['base']);
    }

    public function testMadeBeforeMatchesARecipeByProductOrName(): void
    {
        $beefProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Ground beef', 'category' => 'Meat']);
        $riceProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Rice', 'category' => 'Other']);
        $oliveProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Olives', 'category' => 'Other']);
        $beef = $this->component('Ground beef', 'protein', $beefProduct['id']);
        $rice = $this->component('Rice', 'base', $riceProduct['id']);
        $olives = $this->component('Olives', 'side');
        $this->pair($beef['id'], $rice['id'], 3);
        $this->pair($beef['id'], $olives['id'], 3);
        $this->pair($rice['id'], $olives['id'], 3);

        $match = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Beef and rice',
            'ingredients' => [
                ['product_id' => $beefProduct['id']],
                ['product_id' => $riceProduct['id']],
            ],
        ]);
        $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Beef rice and ranch',
            'ingredients' => [
                ['product_id' => $beefProduct['id']],
                ['product_id' => $riceProduct['id']],
                ['product_id' => $oliveProduct['id']],
            ],
        ]);
        $found = $this->svc->meals->suggest($this->user, [$beef['id'], $rice['id']], false, false)['made_before'];
        self::assertCount(1, $found);
        self::assertSame('recipe', $found[0]['kind']);
        self::assertSame($match['id'], $found[0]['id']);
        self::assertSame('/pantry?view=recipes&recipe=' . $match['id'], $found[0]['url']);
        self::assertSame([], $found[0]['also_uses']);

        $byName = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Olive plate',
            'ingredients' => [['product_id' => $oliveProduct['id']]],
        ]);
        $named = $this->svc->meals->suggest($this->user, [$olives['id']], false, false)['made_before'];
        self::assertSame([$byName['id']], array_column($named, 'id'));

        $saved = $this->svc->meals->saveRecipe($this->user, 'Weeknight beef rice', [$beef['id'], $rice['id']]);
        $again = $this->svc->meals->suggest($this->user, [$beef['id'], $rice['id']], false, false)['made_before'];
        self::assertContains($saved['recipe']['id'], array_column($again, 'id'));
    }

    public function testMadeBeforeIgnoresSupportingAndUnmappedIngredients(): void
    {
        $sausageProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Italian sausage', 'category' => 'Meat']);
        $riceProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Rice', 'category' => 'Other']);
        $onionProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Onion', 'category' => 'Produce']);
        $pepperProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Red bell pepper', 'category' => 'Produce']);
        $soyProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Soy sauce', 'category' => 'Other']);
        $bayProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Old Bay', 'category' => 'Other']);
        $saltProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Salt', 'category' => 'Other']);
        $this->svc->pantry->addItems($this->user, [[
            'product_id' => $onionProduct['id'],
            'quantity' => 1,
            'location' => 'pantry',
        ]], 'david');

        $sausage = $this->component('Italian sausage', 'protein', $sausageProduct['id']);
        $rice = $this->component('Rice', 'base', $riceProduct['id']);
        $onion = $this->component('Onion', 'aromatic', $onionProduct['id']);
        $pepper = $this->component('Red bell pepper', 'vegetable', $pepperProduct['id']);
        $soy = $this->component('Soy sauce', 'sauce', $soyProduct['id']);
        $bay = $this->component('Old Bay', 'spice', $bayProduct['id']);
        $this->pair($sausage['id'], $rice['id'], 4);
        $this->pair($sausage['id'], $soy['id'], 2);

        $italian = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Italian sausage and rice',
            'ingredients' => [
                ['product_id' => $sausageProduct['id']],
                ['product_id' => $riceProduct['id']],
                ['product_id' => $onionProduct['id']],
                ['product_id' => $pepperProduct['id']],
            ],
        ]);
        $withSoy = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Sausage rice and soy',
            'ingredients' => [
                ['product_id' => $sausageProduct['id']],
                ['product_id' => $riceProduct['id']],
                ['product_id' => $soyProduct['id']],
            ],
        ]);
        $sausageOnly = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Sausage alone',
            'ingredients' => [['product_id' => $sausageProduct['id']]],
        ]);
        $aromatics = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Onion and Old Bay',
            'ingredients' => [
                ['product_id' => $onionProduct['id']],
                ['product_id' => $bayProduct['id']],
            ],
        ]);
        $withSalt = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Sausage rice and salt',
            'ingredients' => [
                ['product_id' => $sausageProduct['id']],
                ['product_id' => $riceProduct['id']],
                ['product_id' => $saltProduct['id']],
            ],
        ]);

        $found = $this->svc->meals->suggest($this->user, [$sausage['id'], $rice['id']], false, false)['made_before'];
        $byId = [];
        foreach ($found as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertArrayHasKey($italian['id'], $byId);
        self::assertArrayNotHasKey($withSoy['id'], $byId);
        self::assertArrayNotHasKey($sausageOnly['id'], $byId);
        self::assertArrayNotHasKey($aromatics['id'], $byId);
        self::assertSame([
            [
                'name' => 'Onion',
                'category' => 'aromatic',
                'component_id' => $onion['id'],
                'on_hand' => true,
                'swaps' => [],
            ],
            [
                'name' => 'Red bell pepper',
                'category' => 'vegetable',
                'component_id' => $pepper['id'],
                'on_hand' => false,
                'swaps' => [],
            ],
        ], $byId[$italian['id']]['also_uses']);
        self::assertSame([
            [
                'name' => 'Salt',
                'category' => null,
                'component_id' => null,
                'on_hand' => false,
                'swaps' => [],
            ],
        ], $byId[$withSalt['id']]['also_uses']);

        $sausagePick = $this->svc->meals->suggest($this->user, [$sausage['id']], false, false)['made_before'];
        $sausageIds = array_column($sausagePick, 'id');
        self::assertContains($sausageOnly['id'], $sausageIds);
        self::assertNotContains($aromatics['id'], $sausageIds);
        self::assertNotContains($italian['id'], $sausageIds);
    }

    public function testMatchingRecipesAreSupersetsRankedByExactThenExtraThenOnHand(): void
    {
        $beefProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Ground beef', 'category' => 'Meat']);
        $riceProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Rice', 'category' => 'Other']);
        $noodlesProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Noodles', 'category' => 'Other']);
        $onionProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Onion', 'category' => 'Produce']);
        $oliveProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Olives', 'category' => 'Other']);
        $this->svc->pantry->addItems($this->user, [
            ['product_id' => $beefProduct['id'], 'quantity' => 1, 'location' => 'fridge'],
            ['product_id' => $noodlesProduct['id'], 'quantity' => 1, 'location' => 'pantry'],
            ['product_id' => $onionProduct['id'], 'quantity' => 1, 'location' => 'pantry'],
            ['product_id' => $oliveProduct['id'], 'quantity' => 1, 'location' => 'pantry'],
        ], 'david');

        $beef = $this->component('Ground beef', 'protein', $beefProduct['id']);
        $rice = $this->component('Rice', 'base', $riceProduct['id']);
        $noodles = $this->component('Noodles', 'base', $noodlesProduct['id']);
        $onion = $this->component('Onion', 'aromatic', $onionProduct['id']);
        $olives = $this->component('Olives', 'side', $oliveProduct['id']);
        $this->pair($beef['id'], $rice['id'], 3);

        $exactBare = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Exact bare',
            'ingredients' => [
                ['product_id' => $beefProduct['id']],
                ['product_id' => $riceProduct['id']],
            ],
        ]);
        $exactOnion = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Exact with onion',
            'ingredients' => [
                ['product_id' => $beefProduct['id']],
                ['product_id' => $riceProduct['id']],
                ['product_id' => $onionProduct['id']],
            ],
        ]);
        $withNoodles = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'With noodles',
            'ingredients' => [
                ['product_id' => $beefProduct['id']],
                ['product_id' => $riceProduct['id']],
                ['product_id' => $noodlesProduct['id']],
            ],
        ]);
        $wide = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Wide',
            'ingredients' => [
                ['product_id' => $beefProduct['id']],
                ['product_id' => $riceProduct['id']],
                ['product_id' => $noodlesProduct['id']],
                ['product_id' => $oliveProduct['id']],
            ],
        ]);
        $beefOnly = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Beef only',
            'ingredients' => [['product_id' => $beefProduct['id']]],
        ]);
        $aromatics = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Aromatics only',
            'ingredients' => [['product_id' => $onionProduct['id']]],
        ]);

        $board = $this->svc->meals->suggest($this->user, [$beef['id'], $rice['id']], false, false);
        $matching = $board['matching_recipes'];
        self::assertSame(
            ['Exact with onion', 'Exact bare', 'With noodles', 'Wide'],
            array_column($matching, 'name')
        );
        self::assertSame(
            [$exactOnion['id'], $exactBare['id'], $withNoodles['id'], $wide['id']],
            array_column($matching, 'id')
        );
        self::assertSame([true, true, false, false], array_column($matching, 'exact'));
        self::assertNotContains($beefOnly['id'], array_column($matching, 'id'));
        self::assertNotContains($aromatics['id'], array_column($matching, 'id'));
        self::assertSame(
            ['Exact bare', 'Exact with onion'],
            array_column($board['made_before'], 'name')
        );
        foreach ($board['made_before'] as $row) {
            self::assertSame(
                ['kind', 'id', 'name', 'url', 'also_uses', 'picks', 'protein_g', 'meal_prep', 'keeps_days'],
                array_keys($row)
            );
        }
        $bare = $matching[1];
        self::assertSame(
            ['kind', 'id', 'name', 'url', 'exact', 'picks', 'ingredients', 'protein_g', 'meal_prep', 'keeps_days', 'servings'],
            array_keys($bare)
        );
        self::assertSame([$beef['id'], $rice['id']], $bare['picks']);
        self::assertSame('/pantry?view=recipes&recipe=' . $exactBare['id'], $bare['url']);
        self::assertSame('Rice', $bare['ingredients'][1]['name']);
        self::assertFalse($bare['ingredients'][1]['on_hand']);
        $withOnion = $matching[0];
        self::assertSame([$beef['id'], $rice['id']], $withOnion['picks']);
        self::assertSame('Onion', $withOnion['ingredients'][2]['name']);
        self::assertTrue($withOnion['ingredients'][2]['on_hand']);
        self::assertSame('aromatic', $withOnion['ingredients'][2]['category']);
        self::assertSame($onion['id'], $withOnion['ingredients'][2]['component_id']);
        self::assertSame([$beef['id'], $rice['id'], $noodles['id'], $olives['id']], $matching[3]['picks']);

        $open = $this->svc->meals->suggest($this->user, [], false, false);
        self::assertSame([], $open['made_before']);
        self::assertSame(
            ['Wide', 'Exact with onion', 'With noodles', 'Aromatics only', 'Beef only', 'Exact bare'],
            array_column($open['matching_recipes'], 'name')
        );
        foreach ($open['matching_recipes'] as $row) {
            self::assertFalse($row['exact']);
        }
    }

    public function testAddMissingSendsOnlyAbsentPicksToGroceries(): void
    {
        $beefProduct = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Ground beef', 'category' => 'Meat']);
        $this->svc->pantry->addItems($this->user, [[
            'product_id' => $beefProduct['id'],
            'quantity' => 1,
            'location' => 'fridge',
        ]], 'david');
        $beef = $this->component('Ground beef', 'protein', $beefProduct['id']);
        $rice = $this->component('Rice', 'base');
        $this->pair($beef['id'], $rice['id'], 5);

        $added = $this->svc->meals->addMissing($this->user, [$beef['id'], $rice['id']], 'user');
        self::assertSame(['Rice'], array_column($added['added'], 'name'));
        $rows = $this->db->fetchAll(
            'SELECT name, added_by FROM shopping_items WHERE user_id = ? ORDER BY id',
            [$this->user]
        );
        self::assertSame([['name' => 'Rice', 'added_by' => 'user']], $rows);
        $list = $this->db->fetchOne('SELECT name FROM shopping_lists WHERE user_id = ?', [$this->user]);
        self::assertSame('Groceries', $list['name']);

        $none = $this->svc->meals->addMissing($this->user, [$beef['id']], 'agent');
        self::assertSame([], $none['added']);
    }

    public function testCategoryAndSourceMustBeKnownValues(): void
    {
        try {
            $this->component('Cumin', 'seasoning');
            self::fail('Unknown category was stored.');
        } catch (ValidationException $e) {
            self::assertSame(
                'Category must be protein, base, sauce, side, aromatic, vegetable, or spice.',
                $e->fields()['category']
            );
        }
        try {
            $this->svc->meals->addComponent($this->user, [
                'name' => 'Rice',
                'category' => 'base',
                'source' => 'web',
            ]);
            self::fail('Unknown source was stored.');
        } catch (ValidationException $e) {
            self::assertSame('Source must be david, past_meal, or research.', $e->fields()['source']);
        }
    }

    public function testASessionCanWriteWhileMcpUpdatesAreOff(): void
    {
        self::assertTrue(MealBuilder::mcpWriteAllowed(false, null));
        self::assertFalse(MealBuilder::mcpWriteAllowed(false, 9));
        self::assertTrue(MealBuilder::mcpWriteAllowed(true, 9));
        self::assertSame('meal_plan_day', ApiRunner::entityFromPath('/api/v1/pantry/meals/days'));
        self::assertSame('meal_component', ApiRunner::entityFromPath('/api/v1/pantry/meals/components/4'));
        self::assertSame('recipe', ApiRunner::entityFromPath('/api/v1/pantry/meals/recipe'));
        self::assertSame('pantry_lot', ApiRunner::entityFromPath('/api/v1/pantry/meals/prep'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/pantry/meals/components/4/remove'));
    }

    public function testPrepScalesARecipeAndOrdersTheJar(): void
    {
        $jar = $this->jarKitchen(false);
        $recipe = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Spicy chicken noodle jar',
            'meal_prep' => true,
            'protein_g' => 28.5,
            'servings' => 1,
            'ingredients' => [
                ['product_id' => $jar['chicken_product'], 'quantity' => 4, 'unit' => 'oz'],
                ['product_id' => $jar['noodle_product'], 'quantity' => 80, 'unit' => 'g'],
                ['product_id' => $jar['chili_product'], 'quantity' => 2, 'unit' => 'oz'],
                ['product_id' => $jar['cabbage_product'], 'quantity' => 1, 'unit' => 'each'],
                ['product_id' => $jar['scallion_product'], 'quantity' => 1, 'unit' => 'each'],
            ],
        ]);
        self::assertSame(3, $recipe['keeps_days']);
        self::assertTrue($recipe['meal_prep']);
        self::assertEquals(28.5, $recipe['protein_g']);

        $plan = $this->svc->meals->planPrep($this->user, [
            'servings' => 3,
            'recipe_id' => $recipe['id'],
        ]);
        self::assertSame('Spicy chicken noodle jar x3', $plan['label']);
        self::assertSame('2026-09-28', $plan['use_by']);
        self::assertEquals(28.5, $plan['protein_g']);
        $byName = [];
        foreach ($plan['lines'] as $line) {
            $byName[$line['name']] = $line;
        }
        self::assertSame('12', $byName['Chicken']['quantity']);
        self::assertSame('oz', $byName['Chicken']['unit']);
        self::assertSame('240', $byName['Noodles']['quantity']);
        self::assertSame('6', $byName['Chili sauce']['quantity']);
        self::assertSame('3', $byName['Cabbage']['quantity']);
        self::assertFalse($byName['Chili sauce']['on_hand']);
        self::assertContains('Soy sauce', array_column($byName['Chili sauce']['swaps'], 'name'));

        $steps = $plan['steps'];
        $at = function (string $prefix) use ($steps): int {
            foreach ($steps as $i => $step) {
                if (str_starts_with($step, $prefix)) {
                    return $i;
                }
            }
            self::fail('Missing step ' . $prefix);
        };
        self::assertLessThan($at('Pack raw'), $at('Cook ahead'));
        self::assertStringContainsString('Chicken', $steps[$at('Cook ahead')]);
        self::assertStringContainsString('Noodles', $steps[$at('Cook ahead')]);
        self::assertStringContainsString('Chili sauce', $steps[$at('Pack raw')]);
        self::assertStringContainsString('Cabbage', $steps[$at('Pack raw')]);
        self::assertLessThan($at('Base'), $at('Sauce on the bottom'));
        self::assertLessThan($at('Vegetables'), $at('Base'));
        self::assertLessThan($at('Protein on top'), $at('Vegetables'));
        self::assertLessThan($at('Garnish'), $at('Protein on top'));
        self::assertStringContainsString('hot water', $steps[array_key_last($steps)]);
        self::assertStringContainsString('microwave', $steps[array_key_last($steps)]);

        $combo = $this->svc->meals->planPrep($this->user, [
            'servings' => 3,
            'picks' => [$jar['chicken'], $jar['noodles']],
            'name' => 'Week jars',
        ]);
        self::assertSame('Week jars x3', $combo['label']);
        self::assertSame(3, $combo['keeps_days']);
        $comboQty = [];
        foreach ($combo['lines'] as $line) {
            $comboQty[$line['name']] = $line['quantity'];
        }
        self::assertSame('3', $comboQty['Chicken']);
        self::assertSame('3', $comboQty['Noodles']);
    }

    public function testLogPrepDeductsSoonestLotsAndShowsUpAsExpiring(): void
    {
        $jar = $this->jarKitchen(true);
        $recipe = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Spicy chicken noodle jar',
            'meal_prep' => true,
            'keeps_days' => 3,
            'ingredients' => [
                ['product_id' => $jar['chicken_product'], 'quantity' => 4, 'unit' => 'oz'],
                ['product_id' => $jar['noodle_product'], 'quantity' => 80, 'unit' => 'g'],
                ['product_id' => $jar['chili_product'], 'quantity' => 2, 'unit' => 'oz'],
            ],
        ]);
        $logged = $this->svc->meals->logPrep($this->user, [
            'servings' => 3,
            'recipe_id' => $recipe['id'],
        ], 'david');
        $lot = $logged['lot'];
        self::assertSame('Spicy chicken noodle jar x3', $lot['name']);
        self::assertSame('fridge', $lot['location']);
        self::assertSame('serving', $lot['unit']);
        self::assertEquals(3, $lot['quantity']);
        self::assertSame('on_hand', $lot['status']);
        self::assertSame('2026-09-28', $lot['use_by']);
        self::assertTrue($lot['use_by_overridden']);

        $lots = [];
        foreach ($this->svc->pantry->listLots($this->user, ['status' => 'any'])['lots'] as $row) {
            $lots[(int) $row['id']] = $row;
        }
        self::assertSame('used', $lots[$jar['chicken_soon']]['status']);
        self::assertEquals(0, $lots[$jar['chicken_soon']]['quantity']);
        self::assertSame('on_hand', $lots[$jar['chicken_later']]['status']);
        self::assertEquals(10, $lots[$jar['chicken_later']]['quantity']);
        $noodle = null;
        foreach ($lots as $row) {
            if ($row['name'] === 'Noodles') {
                $noodle = $row;
            }
        }
        self::assertNotNull($noodle);
        self::assertSame('used', $noodle['status']);
        self::assertEquals(0, $noodle['quantity']);

        $names = array_column($this->svc->pantry->expiring($this->user, 3)['lots'], 'name');
        self::assertContains('Spicy chicken noodle jar x3', $names);
        $widget = array_column($this->svc->pantry->summary($this->user, 3)['expiring'], 'name');
        self::assertContains('Spicy chicken noodle jar x3', $widget);
    }

    public function testLogPrepRefusesAShortPantryWithoutGoingNegative(): void
    {
        $jar = $this->jarKitchen(false);
        $this->stock($jar['chicken_product'], 1, 'oz', '2026-10-01');
        $recipe = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Spicy chicken noodle jar',
            'ingredients' => [
                ['product_id' => $jar['chicken_product'], 'quantity' => 4, 'unit' => 'oz'],
                ['product_id' => $jar['noodle_product'], 'quantity' => 1, 'unit' => 'g'],
            ],
        ]);
        try {
            $this->svc->meals->logPrep($this->user, [
                'servings' => 3,
                'recipe_id' => $recipe['id'],
            ], 'david');
            self::fail('A short pantry was logged.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Not enough Chicken', $e->fields()['quantity']);
            self::assertStringContainsString('have 1', $e->fields()['quantity']);
            self::assertStringContainsString('asked 12', $e->fields()['quantity']);
        }
        $left = $this->svc->pantry->onHandAmount($this->user, $jar['chicken_product']);
        self::assertEquals(1, $left['have']);
        $names = array_column($this->svc->pantry->listLots($this->user, ['status' => 'any'])['lots'], 'name');
        self::assertNotContains('Spicy chicken noodle jar x3', $names);

        $bare = $this->component('Loose herb', 'aromatic');
        $open = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Herb jar',
            'ingredients' => [
                ['product_id' => $jar['noodle_product'], 'quantity' => 1, 'unit' => 'g'],
                ['category' => 'Produce'],
            ],
        ]);
        $preview = $this->svc->meals->planPrep($this->user, ['servings' => 1, 'recipe_id' => $open['id']]);
        self::assertContains('Produce', $preview['unlinked']);
        try {
            $this->svc->meals->logPrep($this->user, ['servings' => 1, 'recipe_id' => $open['id']], 'david');
            self::fail('An unlinked ingredient was logged.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Link a pantry product for Produce', $e->fields()['product_id']);
        }
        self::assertSame($bare['name'], 'Loose herb');
    }

    public function testOffHandPickOffersAnOnHandSwapThatPairsWithTheOthers(): void
    {
        $jar = $this->jarKitchen(false);
        $board = $this->svc->meals->suggest($this->user, [$jar['chicken'], $jar['noodles']], false, false);
        $chicken = null;
        foreach ($board['picks'] as $pick) {
            if ($pick['id'] === $jar['chicken']) {
                $chicken = $pick;
            }
        }
        self::assertNotNull($chicken);
        self::assertFalse($chicken['on_hand']);
        self::assertContains('Turkey', array_column($chicken['swaps'], 'name'));
        self::assertNotContains('Chicken', array_column($chicken['swaps'], 'name'));
        $sauceNames = $this->names($board['categories']['sauce']);
        self::assertNotContains('Turkey', $sauceNames);
    }

    public function testWeekdayPlanListsMondayThroughFriday(): void
    {
        $empty = $this->svc->meals->weekPlan($this->user, '2026-09-25');
        self::assertSame('2026-09-21', $empty['week_start']);
        self::assertSame('2026-09-25', $empty['week_end']);
        self::assertSame(
            ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
            array_column($empty['days'], 'weekday')
        );
        self::assertSame(
            ['2026-09-21', '2026-09-22', '2026-09-23', '2026-09-24', '2026-09-25'],
            array_column($empty['days'], 'date')
        );
        self::assertSame(['SEP 21', 'SEP 22', 'SEP 23', 'SEP 24', 'SEP 25'], array_column($empty['days'], 'label'));
        self::assertNull($empty['days'][0]['dinner']);
        self::assertNull($empty['days'][0]['lunch']);

        $saturday = $this->svc->meals->weekPlan($this->user, '2026-09-26');
        self::assertSame('2026-09-21', $saturday['week_start']);

        $saved = $this->svc->meals->savePlanDay($this->user, [
            'plan_date' => '2026-09-21',
            'slot' => 'dinner',
            'title' => 'Lemon herb chicken',
        ]);
        self::assertSame($saved['day']['id'], ApiRunner::entityIdFromPayload($saved));
        self::assertSame('dinner', $saved['day']['slot']);
        self::assertSame('Lemon herb chicken', $saved['day']['title']);
        self::assertNull($saved['day']['line']);

        $lunch = $this->svc->meals->savePlanDay($this->user, [
            'plan_date' => '2026-09-21',
            'slot' => 'lunch',
            'title' => 'Rice bowl',
            'detail' => 'Use the leftover rice',
        ]);
        self::assertSame('Use the leftover rice', $lunch['day']['detail']);
        self::assertSame('Use the leftover rice', $lunch['day']['line']);

        $replaced = $this->svc->meals->savePlanDay($this->user, [
            'plan_date' => '2026-09-21',
            'slot' => 'dinner',
            'title' => 'Miso salmon bowls',
        ]);
        self::assertSame($saved['day']['id'], $replaced['day']['id']);
        self::assertSame(1, (int) $this->db->fetchScalar(
            "SELECT COUNT(*) FROM meal_plan_days WHERE user_id = ? AND plan_date = '2026-09-21' AND slot = 'dinner'",
            [$this->user]
        ));

        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('other', 'o@x', 'h')");
        $other = (int) $this->db->lastInsertId();
        $this->svc->meals->savePlanDay($other, [
            'plan_date' => '2026-09-22',
            'slot' => 'dinner',
            'title' => 'Hidden dinner',
        ]);

        $week = $this->svc->meals->weekPlan($this->user, $this->svc->clock->today());
        $monday = $week['days'][0];
        self::assertSame('Miso salmon bowls', $monday['dinner']['title']);
        self::assertSame('Rice bowl', $monday['lunch']['title']);
        self::assertNull($week['days'][1]['dinner']);

        $cleared = $this->svc->meals->clearPlanDay($this->user, '2026-09-21', 'lunch');
        self::assertSame(1, $cleared['removed']);
        self::assertNull($cleared['week']['days'][0]['lunch']);
        self::assertSame('Miso salmon bowls', $cleared['week']['days'][0]['dinner']['title']);
        self::assertSame(0, $this->svc->meals->clearPlanDay($this->user, '2026-09-21', 'lunch')['removed']);
    }

    public function testWeekdayPlanLineUsesTheRecipeOnTheShelf(): void
    {
        $jar = $this->jarKitchen(false);
        $recipe = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Spicy chicken noodle jar',
            'meal_prep' => true,
            'protein_g' => 28.5,
            'servings' => 1,
            'ingredients' => [
                ['product_id' => $jar['chicken_product'], 'quantity' => 4, 'unit' => 'oz'],
                ['product_id' => $jar['noodle_product'], 'quantity' => 80, 'unit' => 'g'],
                ['product_id' => $jar['chili_product'], 'quantity' => 2, 'unit' => 'oz'],
                ['product_id' => $jar['cabbage_product'], 'quantity' => 1, 'unit' => 'each'],
                ['product_id' => $jar['scallion_product'], 'quantity' => 1, 'unit' => 'each'],
            ],
        ]);
        $saved = $this->svc->meals->savePlanDay($this->user, [
            'plan_date' => '2026-09-23',
            'slot' => 'dinner',
            'title' => '',
            'recipe_id' => $recipe['id'],
        ]);
        self::assertSame('Spicy chicken noodle jar', $saved['day']['title']);
        self::assertSame($recipe['id'], $saved['day']['recipe_id']);
        self::assertFalse($saved['day']['pantry_ready']);
        self::assertSame('28.5 g protein · Keeps 3 days', $saved['day']['line']);

        $this->stock($jar['chicken_product'], 4, 'oz', '2026-10-01');
        $this->stock($jar['chili_product'], 2, 'oz', '2026-10-01');
        $ready = $this->svc->meals->weekPlan($this->user, '2026-09-23');
        self::assertTrue($ready['days'][2]['dinner']['pantry_ready']);
        self::assertSame('28.5 g protein · Keeps 3 days · Pantry ready', $ready['days'][2]['dinner']['line']);
    }

    public function testWeekdayPlanRejectsAWeekendAndAnUnknownRecipe(): void
    {
        try {
            $this->svc->meals->savePlanDay($this->user, [
                'plan_date' => '2026-09-26',
                'slot' => 'dinner',
                'title' => 'Saturday',
            ]);
            self::fail('Saturday was stored.');
        } catch (ValidationException $e) {
            self::assertSame('The planner is Monday through Friday.', $e->fields()['plan_date']);
        }
        try {
            $this->svc->meals->savePlanDay($this->user, [
                'plan_date' => '2026-09-27',
                'slot' => 'lunch',
                'title' => 'Sunday',
            ]);
            self::fail('Sunday was stored.');
        } catch (ValidationException $e) {
            self::assertSame('The planner is Monday through Friday.', $e->fields()['plan_date']);
        }
        try {
            $this->svc->meals->savePlanDay($this->user, [
                'plan_date' => '2026-09-22',
                'slot' => 'brunch',
                'title' => 'Eggs',
            ]);
            self::fail('An unknown slot was stored.');
        } catch (ValidationException $e) {
            self::assertSame('Slot must be lunch or dinner.', $e->fields()['slot']);
        }
        try {
            $this->svc->meals->savePlanDay($this->user, [
                'plan_date' => '2026-09-22',
                'slot' => 'dinner',
                'title' => '',
            ]);
            self::fail('A blank dinner was stored.');
        } catch (ValidationException $e) {
            self::assertSame('Enter a name.', $e->fields()['title']);
        }
        try {
            $this->svc->meals->savePlanDay($this->user, [
                'plan_date' => '2026-09-22',
                'slot' => 'dinner',
                'title' => 'Ghost',
                'recipe_id' => 999999,
            ]);
            self::fail('An unknown recipe was linked.');
        } catch (ValidationException $e) {
            self::assertSame('Recipe not found.', $e->fields()['recipe_id']);
        }
    }

    /**
     * @return array<string, int>
     */
    private function jarKitchen(bool $stocked): array
    {
        $chicken = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Chicken', 'category' => 'Meat', 'default_unit' => 'oz']);
        $noodles = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Noodles', 'category' => 'Other', 'default_unit' => 'g']);
        $chili = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Chili sauce', 'category' => 'Other', 'default_unit' => 'oz']);
        $soy = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Soy sauce', 'category' => 'Other', 'default_unit' => 'oz']);
        $cabbage = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Cabbage', 'category' => 'Produce', 'default_unit' => 'each']);
        $scallion = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Scallion', 'category' => 'Produce', 'default_unit' => 'each']);
        $turkey = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Turkey', 'category' => 'Meat', 'default_unit' => 'oz']);
        $ids = [
            'chicken_product' => (int) $chicken['id'],
            'noodle_product' => (int) $noodles['id'],
            'chili_product' => (int) $chili['id'],
            'soy_product' => (int) $soy['id'],
            'cabbage_product' => (int) $cabbage['id'],
            'scallion_product' => (int) $scallion['id'],
            'turkey_product' => (int) $turkey['id'],
        ];
        $ids['chicken'] = $this->component('Chicken', 'protein', $ids['chicken_product'])['id'];
        $ids['noodles'] = $this->component('Noodles', 'base', $ids['noodle_product'])['id'];
        $ids['chili'] = $this->component('Chili sauce', 'sauce', $ids['chili_product'])['id'];
        $ids['soy'] = $this->component('Soy sauce', 'sauce', $ids['soy_product'])['id'];
        $ids['cabbage'] = $this->component('Cabbage', 'vegetable', $ids['cabbage_product'])['id'];
        $ids['scallion'] = $this->component('Scallion', 'aromatic', $ids['scallion_product'])['id'];
        $ids['turkey'] = $this->component('Turkey', 'protein', $ids['turkey_product'])['id'];
        foreach ([
            ['chicken', 'noodles'],
            ['chicken', 'chili'],
            ['chicken', 'soy'],
            ['noodles', 'chili'],
            ['noodles', 'soy'],
            ['turkey', 'noodles'],
            ['turkey', 'soy'],
        ] as [$a, $b]) {
            $this->pair($ids[$a], $ids[$b], 4);
        }
        $this->stock($ids['soy_product'], 8, 'oz', '2026-10-20');
        $this->stock($ids['turkey_product'], 20, 'oz', '2026-10-20');
        $this->stock($ids['cabbage_product'], 6, 'each', '2026-10-20');
        $this->stock($ids['scallion_product'], 6, 'each', '2026-10-20');
        if ($stocked) {
            $ids['chicken_soon'] = $this->stock($ids['chicken_product'], 2, 'oz', '2026-09-26');
            $ids['chicken_later'] = $this->stock($ids['chicken_product'], 20, 'oz', '2026-10-01');
            $this->stock($ids['noodle_product'], 240, 'g', '2026-10-15');
            $this->stock($ids['chili_product'], 6, 'oz', '2026-10-15');
        } else {
            $this->stock($ids['noodle_product'], 300, 'g', '2026-10-15');
        }
        return $ids;
    }

    private function stock(int $productId, float $quantity, string $unit, string $useBy): int
    {
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $productId,
            'quantity' => $quantity,
            'unit' => $unit,
            'location' => 'fridge',
            'use_by' => $useBy,
        ]], 'david')['lots'][0];
        return (int) $lot['id'];
    }

    /**
     * @return array<string, int>
     */
    private function kitchen(): array
    {
        $names = [
            'beef' => ['Ground beef', 'protein'],
            'turkey' => ['Turkey', 'protein'],
            'salmon' => ['Salmon', 'protein'],
            'sausage' => ['Sausage', 'protein'],
            'rice' => ['Rice', 'base'],
            'noodles' => ['Noodles', 'base'],
            'potatoes' => ['Potatoes', 'base'],
            'greens' => ['Mixed greens', 'base'],
            'soy' => ['Soy sauce', 'sauce'],
            'ranch' => ['Ranch', 'sauce'],
            'chipotle' => ['Chipotle mayo', 'sauce'],
            'olives' => ['Olives', 'side'],
            'pickles' => ['Spicy pickles', 'side'],
            'cakes' => ['Japanese fish cakes', 'side'],
            'onion' => ['Onion', 'aromatic'],
            'garlic' => ['Garlic', 'aromatic'],
            'pepper' => ['Bell pepper', 'vegetable'],
            'oldbay' => ['Old Bay', 'spice'],
        ];
        $ids = [];
        foreach ($names as $key => [$name, $category]) {
            $ids[$key] = $this->component($name, $category)['id'];
        }
        foreach ([
            ['beef', 'noodles', 3],
            ['beef', 'potatoes', 3],
            ['beef', 'rice', 5],
            ['beef', 'soy', 4],
            ['beef', 'ranch', 1],
            ['beef', 'chipotle', 4],
            ['beef', 'olives', 3],
            ['beef', 'pickles', 2],
            ['beef', 'turkey', 2],
            ['beef', 'sausage', 2],
            ['turkey', 'sausage', 2],
            ['turkey', 'rice', 4],
            ['rice', 'soy', 5],
            ['rice', 'olives', 3],
            ['beef', 'onion', 5],
            ['rice', 'onion', 1],
            ['beef', 'garlic', 4],
        ] as [$a, $b, $score]) {
            $this->pair($ids[$a], $ids[$b], $score);
        }
        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function component(string $name, string $category, ?int $productId = null): array
    {
        $fields = ['name' => $name, 'category' => $category, 'source' => 'david'];
        if ($productId !== null) {
            $fields['product_id'] = $productId;
        }
        return $this->svc->meals->addComponent($this->user, $fields)['component'];
    }

    private function pair(int $a, int $b, ?int $score): void
    {
        $this->svc->meals->addPairing($this->user, [
            'component_a_id' => $a,
            'component_b_id' => $b,
            'score' => $score,
            'source' => 'david',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function names(array $rows): array
    {
        return array_column($rows, 'name');
    }
}
