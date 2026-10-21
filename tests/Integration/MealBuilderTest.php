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
        self::assertSame('meal_component', ApiRunner::entityFromPath('/api/v1/pantry/meals/components/4'));
        self::assertSame('recipe', ApiRunner::entityFromPath('/api/v1/pantry/meals/recipe'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/pantry/meals/components/4/remove'));
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
