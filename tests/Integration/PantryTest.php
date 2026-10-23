<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class PantryTest extends PgsqlTestCase
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

    public function testSplitEatFreezeAndKeepRecalculatesOnlyTheMovedPiece(): void
    {
        $product = $this->sausage();
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 5,
            'location' => 'fridge',
            'purchased_at' => '2026-09-25',
        ]], 'david')['lots'][0];
        self::assertSame('2026-09-28', $lot['use_by']);
        self::assertFalse($lot['use_by_overridden']);

        $split = $this->svc->pantry->splitOrMove($this->user, $lot['id'], [
            'pieces' => [
                ['quantity' => 1, 'consume' => true],
                ['quantity' => 2, 'location' => 'freezer'],
                ['quantity' => 2, 'location' => 'fridge'],
            ],
        ], 'david');

        self::assertSame('used', $split['parent']['status']);
        self::assertEquals(0.0, $split['parent']['quantity']);
        self::assertCount(2, $split['lots']);
        $byPlace = [];
        foreach ($split['lots'] as $child) {
            $byPlace[$child['location']] = $child;
            self::assertSame($lot['id'], $child['parent_lot_id']);
        }
        self::assertSame('2026-09-28', $byPlace['fridge']['use_by']);
        self::assertEquals(2.0, $byPlace['fridge']['quantity']);
        self::assertSame('2026-12-24', $byPlace['freezer']['use_by']);
        self::assertFalse($byPlace['freezer']['use_by_overridden']);

        $actions = $this->db->fetchAll(
            'SELECT lot_id, action FROM pantry_events WHERE user_id = ? ORDER BY id',
            [$this->user]
        );
        $parentActions = array_values(array_filter(
            $actions,
            static fn (array $row): bool => (int) $row['lot_id'] === $lot['id']
        ));
        self::assertSame(['add', 'consume', 'split'], array_column($parentActions, 'action'));
        self::assertSame('add', $actions[array_key_last($actions)]['action']);
    }

    public function testMoveKeepsAnOverrideUntilResetAndOpenTightensUseBy(): void
    {
        $product = $this->sausage();
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 1,
            'location' => 'fridge',
            'purchased_at' => '2026-09-01',
            'use_by' => '2026-12-01',
        ]], 'david')['lots'][0];
        self::assertTrue($lot['use_by_overridden']);

        $moved = $this->svc->pantry->splitOrMove($this->user, $lot['id'], ['location' => 'freezer'], 'david')['lot'];
        self::assertSame('freezer', $moved['location']);
        self::assertSame('2026-12-01', $moved['use_by']);
        self::assertTrue($moved['use_by_overridden']);

        $reset = $this->svc->pantry->splitOrMove($this->user, $lot['id'], [
            'location' => 'freezer',
            'reset_use_by' => true,
        ], 'david')['lot'];
        self::assertSame('2026-12-24', $reset['use_by']);
        self::assertFalse($reset['use_by_overridden']);

        $fresh = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 1,
            'location' => 'fridge',
            'purchased_at' => '2026-09-25',
        ]], 'david')['lots'][0];
        $opened = $this->svc->pantry->openLot($this->user, $fresh['id'], 'david')['lot'];
        self::assertSame('2026-09-27', $opened['use_by']);
        self::assertNotNull($opened['opened_at']);
        try {
            $this->svc->pantry->openLot($this->user, $fresh['id'], 'david');
            self::fail('expected a second open to fail');
        } catch (ValidationException $e) {
            self::assertStringContainsString('already open', $e->fields()['lot_id']);
        }
    }

    public function testConsumeTakesTheSoonestUseByAndRollsBackAShortfall(): void
    {
        $milk = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Milk',
            'category' => 'Dairy',
            'default_unit' => 'each',
        ]);
        $soon = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $milk['id'],
            'quantity' => 1,
            'location' => 'fridge',
            'use_by' => '2026-09-26',
        ]], 'david')['lots'][0];
        $later = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $milk['id'],
            'quantity' => 2,
            'location' => 'fridge',
            'use_by' => '2026-10-10',
        ]], 'david')['lots'][0];

        $eaten = $this->svc->pantry->consume($this->user, ['name' => 'milk', 'quantity' => 2], 'david');
        self::assertEquals(2.0, $eaten['quantity']);
        self::assertSame('used', $eaten['consumed'][0]['status']);
        self::assertSame($soon['id'], $eaten['consumed'][0]['id']);
        self::assertEquals(1.0, $eaten['consumed'][1]['quantity']);
        self::assertSame($later['id'], $eaten['consumed'][1]['id']);

        try {
            $this->svc->pantry->consume($this->user, ['name' => 'Milk', 'quantity' => 5], 'david');
            self::fail('expected a shortfall');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Not enough Milk', $e->fields()['quantity']);
        }
        $left = $this->svc->pantry->listLots($this->user, ['status' => 'on_hand'])['lots'];
        self::assertCount(1, $left);
        self::assertEquals(1.0, $left[0]['quantity']);
    }

    public function testPurgeTossesSelectedLotsAndWritesAnEventEach(): void
    {
        $product = $this->sausage();
        $past = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 1,
            'location' => 'fridge',
            'use_by' => '2026-09-20',
        ]], 'david')['lots'][0];
        $soon = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 1,
            'location' => 'fridge',
            'purchased_at' => '2026-09-25',
        ]], 'david')['lots'][0];
        $due = $this->svc->pantry->expiring($this->user, 3)['lots'];
        self::assertSame([$past['id'], $soon['id']], array_column($due, 'id'));

        $tossed = $this->svc->pantry->toss($this->user, [$past['id']], 'david')['tossed'];
        self::assertSame('tossed', $tossed[0]['status']);
        $event = $this->db->fetchOne(
            "SELECT action, by_name FROM pantry_events WHERE lot_id = ? AND action = 'toss'",
            [$past['id']]
        );
        self::assertSame('toss', $event['action']);
        self::assertSame('david', $event['by_name']);
        $still = $this->svc->pantry->listLots($this->user, ['status' => 'on_hand'])['lots'];
        self::assertSame([$soon['id']], array_column($still, 'id'));
        $any = $this->svc->pantry->listLots($this->user, ['status' => 'any'])['lots'];
        self::assertNotContains($past['id'], array_column($any, 'id'));
        self::assertContains($soon['id'], array_column($any, 'id'));
    }

    public function testLowStockSkipsAnUncheckedShoppingName(): void
    {
        $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Milk',
            'category' => 'Dairy',
            'default_unit' => 'each',
            'min_on_hand' => 3,
        ]);
        $this->svc->pantry->addItems($this->user, [[
            'name' => 'Milk',
            'quantity' => 1,
            'location' => 'fridge',
        ]], 'david');
        $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Eggs',
            'category' => 'Dairy',
            'default_unit' => 'dozen',
            'min_on_hand' => 2,
        ]);
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $this->svc->shopping->addItems($this->user, $listId, ['Milk'], 'user');

        $result = $this->svc->pantry->lowStock($this->user, true, $listId, 'david');
        self::assertSame(['Eggs', 'Milk'], array_column($result['products'], 'name'));
        $open = $this->svc->shopping->items($this->user, $listId, false, 0);
        $byName = [];
        foreach ($open as $item) {
            $byName[$item['name']] = $item;
        }
        self::assertEquals(1.0, $byName['Milk']['quantity']);
        self::assertEquals(2.0, $byName['Eggs']['quantity']);
        $skipped = array_values(array_filter(
            $result['added'],
            static fn (array $row): bool => ($row['skipped'] ?? false) === true
        ));
        self::assertSame('Milk', $skipped[0]['name']);
    }

    public function testImportOrderCreatesNamedLinesAndReturnsUnmatched(): void
    {
        $beans = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Example beans',
            'upc' => '012345678905',
            'category' => 'Pantry',
        ]);
        $this->svc->pantry->upsertProduct($this->user, ['name' => 'Twin item', 'brand' => 'A']);
        $this->svc->pantry->upsertProduct($this->user, ['name' => 'Twin item', 'brand' => 'B']);

        $result = $this->svc->pantry->importOrder($this->user, [
            'store' => 'Costco',
            'order_ref' => 'A1',
            'purchased_at' => '2026-09-25',
            'items' => [
                ['upc' => '012345678905', 'qty' => 2, 'price' => 3.5],
                ['name' => 'Canned tomatoes', 'qty' => 1],
                ['upc' => '999999999999'],
                ['name' => 'Twin item'],
                ['name' => 'Example beans', 'upc' => '111111111117'],
            ],
        ], 'david');

        self::assertCount(2, $result['lots']);
        self::assertSame($beans['id'], $result['lots'][0]['product_id']);
        self::assertEquals(2.0, $result['lots'][0]['quantity']);
        self::assertSame('Costco', $result['lots'][0]['store']);
        self::assertSame('A1', $result['lots'][0]['order_ref']);
        self::assertEquals(3.5, $result['lots'][0]['unit_price']);
        self::assertSame('Canned tomatoes', $result['lots'][1]['name']);
        self::assertSame([2, 3, 4], array_column($result['unmatched'], 'index'));
        self::assertSame('No product with that UPC, and the line has no name.', $result['unmatched'][0]['reason']);
        self::assertSame('Name matches more than one product.', $result['unmatched'][1]['reason']);
        self::assertSame('That name already has a different UPC.', $result['unmatched'][2]['reason']);

        $created = $this->svc->pantry->listProducts($this->user, 'Canned tomatoes');
        self::assertSame('order', $created[0]['source']);
        $twins = $this->svc->pantry->listProducts($this->user, 'Twin item');
        self::assertCount(2, $twins);
    }

    public function testVaultPageSeedsProductsOnceAndProduceHasNoUpc(): void
    {
        $body = "| UPC | Name | Brand |\n| --- | --- | --- |\n| 012345678905 | Vault beans | BrandX |\n| nope | Skip me | |\n";
        $this->db->execute(
            "INSERT INTO pages (user_id, title, slug, kind, body) VALUES (?, 'David — Shopping Preferences', 'shopping-preferences', 'reference', ?)",
            [$this->user, $body]
        );
        $seeded = $this->svc->pantry->listProducts($this->user, 'Vault beans');
        self::assertCount(1, $seeded);
        self::assertSame('012345678905', $seeded[0]['upc']);
        self::assertSame('vault', $seeded[0]['source']);
        self::assertSame('BrandX', $seeded[0]['brand']);

        $this->svc->pantry->listProducts($this->user, 'Vault beans');
        self::assertSame(1, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM pantry_products WHERE user_id = ? AND upc = ?',
            [$this->user, '012345678905']
        ));

        $onion = null;
        foreach ($this->svc->pantry->listProducts($this->user, 'Onion') as $row) {
            if ($row['name'] === 'Onion') {
                $onion = $row;
            }
        }
        self::assertNotNull($onion);
        self::assertNull($onion['upc']);
        self::assertSame('produce', $onion['source']);
    }

    public function testUnitsAcceptSlicesPiecesAndServings(): void
    {
        $units = $this->svc->pantry->units()['units'];
        self::assertContains('slice', $units);
        self::assertContains('piece', $units);
        self::assertContains('serving', $units);

        $product = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Rye',
            'category' => 'Bakery',
            'default_unit' => 'slices',
        ]);
        self::assertSame('slice', $product['default_unit']);
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 4,
            'unit' => 'pieces',
            'location' => 'pantry',
        ]], 'david')['lots'][0];
        self::assertSame('piece', $lot['unit']);
        $adjusted = $this->svc->pantry->adjust($this->user, $lot['id'], ['unit' => 'slices'], 'david')['lot'];
        self::assertSame('slice', $adjusted['unit']);
        $serving = $this->svc->pantry->adjust($this->user, $lot['id'], ['unit' => 'servings'], 'david')['lot'];
        self::assertSame('serving', $serving['unit']);
        try {
            $this->svc->pantry->adjust($this->user, $lot['id'], ['unit' => 'wedge'], 'david');
            self::fail('expected an unknown unit');
        } catch (ValidationException $e) {
            self::assertSame('Unknown unit.', $e->fields()['unit']);
        }
    }

    public function testPackSizeExpandsAPackageAndLeavesAPieceCountAlone(): void
    {
        $bread = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Bread',
            'category' => 'Bakery',
            'default_unit' => 'pkg',
            'units_per_package' => 11,
            'piece_unit' => 'slices',
            'shelf_life_pantry_days' => 5,
        ]);
        self::assertSame(11, $bread['units_per_package']);
        self::assertSame('slice', $bread['piece_unit']);

        $loaf = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $bread['id'],
            'quantity' => 1,
            'location' => 'pantry',
        ]], 'david')['lots'][0];
        self::assertEquals(11.0, $loaf['quantity']);
        self::assertSame('slice', $loaf['unit']);

        $two = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $bread['id'],
            'quantity' => 2,
            'unit' => 'slice',
            'location' => 'pantry',
        ]], 'david')['lots'][0];
        self::assertEquals(2.0, $two['quantity']);
        self::assertSame('slice', $two['unit']);

        $byWeight = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $bread['id'],
            'quantity' => 1,
            'unit' => 'lb',
            'location' => 'pantry',
        ]], 'david')['lots'][0];
        self::assertEquals(1.0, $byWeight['quantity']);
        self::assertSame('lb', $byWeight['unit']);

        $rice = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Rice',
            'category' => 'Pantry',
            'default_unit' => 'lb',
        ]);
        self::assertNull($rice['units_per_package']);
        $plain = $this->svc->pantry->addItems($this->user, [[
            'name' => 'Rice',
            'quantity' => 2,
            'location' => 'pantry',
        ]], 'david')['lots'][0];
        self::assertEquals(2.0, $plain['quantity']);
        self::assertSame('lb', $plain['unit']);

        $soda = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Dr Pepper',
            'upc' => '012345678905',
            'category' => 'Beverages',
            'units_per_package' => 6,
            'piece_unit' => 'bottles',
        ]);
        $imported = $this->svc->pantry->importOrder($this->user, [
            'store' => 'Walmart',
            'items' => [
                ['upc' => '012345678905', 'qty' => 1, 'unit' => 'pkg'],
                ['name' => 'Dr Pepper', 'qty' => 2, 'unit' => 'bottle'],
                ['name' => 'Rice', 'qty' => 1, 'unit' => 'each'],
            ],
        ], 'david');
        self::assertEquals(6.0, $imported['lots'][0]['quantity']);
        self::assertSame('bottle', $imported['lots'][0]['unit']);
        self::assertEquals(2.0, $imported['lots'][1]['quantity']);
        self::assertSame('bottle', $imported['lots'][1]['unit']);
        self::assertEquals(1.0, $imported['lots'][2]['quantity']);
        self::assertSame('each', $imported['lots'][2]['unit']);
        $listed = $this->svc->pantry->listProducts($this->user, 'Dr Pepper');
        self::assertSame($soda['id'], $listed[0]['id']);
        self::assertSame(6, $listed[0]['units_per_package']);
        self::assertSame('bottle', $listed[0]['piece_unit']);
        $byUpc = $this->svc->pantry->productByUpc($this->user, '012345678905', false);
        self::assertSame(6, $byUpc['product']['units_per_package']);
        self::assertSame('bottle', $byUpc['product']['piece_unit']);
    }

    public function testConsumeWithoutQuantityTakesOneUnit(): void
    {
        $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Dasani',
            'category' => 'Beverages',
            'default_unit' => 'bottle',
        ]);
        $this->svc->pantry->addItems($this->user, [[
            'name' => 'Dasani',
            'quantity' => 5,
            'location' => 'pantry',
        ]], 'david');
        $once = $this->svc->pantry->consume($this->user, ['name' => 'Dasani'], 'david');
        self::assertEquals(1.0, $once['quantity']);
        self::assertEquals(4.0, $once['consumed'][0]['quantity']);
        self::assertSame('on_hand', $once['consumed'][0]['status']);
        $blank = $this->svc->pantry->consume($this->user, ['name' => 'Dasani', 'quantity' => ''], 'david');
        self::assertEquals(3.0, $blank['consumed'][0]['quantity']);
    }

    public function testCondimentStepsDownAndLowStockFlagsLowOrEmpty(): void
    {
        $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Ketchup',
            'category' => 'Pantry',
            'default_unit' => 'bottle',
        ]);
        $lot = $this->svc->pantry->addItems($this->user, [[
            'name' => 'Ketchup',
            'quantity' => 1,
            'unit' => 'bottle',
            'qty_level' => 'full',
            'location' => 'fridge',
        ]], 'david')['lots'][0];
        self::assertSame('full', $lot['qty_level']);

        $half = $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id']], 'david')['consumed'][0];
        self::assertSame('half', $half['qty_level']);
        self::assertSame('on_hand', $half['status']);
        $low = $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id']], 'david')['consumed'][0];
        self::assertSame('low', $low['qty_level']);
        self::assertSame('on_hand', $low['status']);

        $flagged = $this->svc->pantry->lowStock($this->user, false, null, 'david')['products'];
        self::assertSame(['Ketchup'], array_column($flagged, 'name'));
        self::assertSame('low', $flagged[0]['qty_level']);
        self::assertSame('level', $flagged[0]['reason']);

        $empty = $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id']], 'david')['consumed'][0];
        self::assertSame('empty', $empty['qty_level']);
        self::assertSame('used', $empty['status']);
        self::assertEquals(0.0, $empty['quantity']);
        $usedUp = $this->svc->pantry->lowStock($this->user, false, null, 'david')['products'];
        self::assertSame('empty', $usedUp[0]['qty_level']);
        self::assertSame('level', $usedUp[0]['reason']);

        $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Mustard',
            'category' => 'Pantry',
            'default_unit' => 'bottle',
            'min_on_hand' => 1,
        ]);
        $this->svc->pantry->addItems($this->user, [[
            'name' => 'Mustard',
            'quantity' => 4,
            'qty_level' => 'low',
            'location' => 'fridge',
        ]], 'david');
        $both = $this->svc->pantry->lowStock($this->user, false, null, 'david')['products'];
        $byName = [];
        foreach ($both as $row) {
            $byName[$row['name']] = $row;
        }
        self::assertSame('level', $byName['Mustard']['reason']);
        self::assertSame('low', $byName['Mustard']['qty_level']);
        self::assertEquals(4.0, $byName['Mustard']['on_hand']);

        try {
            $this->svc->pantry->consume($this->user, ['name' => 'Mustard', 'quantity' => 0.5], 'david');
            self::fail('expected a whole number of uses');
        } catch (ValidationException $e) {
            self::assertStringContainsString('whole number', $e->fields()['quantity']);
        }
    }

    public function testConsumeOneThenSplitTheRemainingUnitsToFridgeAndFreezer(): void
    {
        $bread = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Sourdough',
            'category' => 'Bakery',
            'default_unit' => 'pkg',
            'units_per_package' => 5,
            'piece_unit' => 'slices',
            'shelf_life_pantry_days' => 4,
            'shelf_life_fridge_days' => 7,
            'shelf_life_freezer_days' => 90,
        ]);
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $bread['id'],
            'quantity' => 1,
            'location' => 'pantry',
            'purchased_at' => '2026-09-01',
        ]], 'david')['lots'][0];
        self::assertEquals(5.0, $lot['quantity']);
        self::assertSame('slice', $lot['unit']);
        self::assertSame('2026-09-01', $lot['purchased_at']);
        self::assertSame('2026-09-05', $lot['use_by']);

        $eaten = $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id']], 'david');
        self::assertEquals(1.0, $eaten['quantity']);
        self::assertEquals(4.0, $eaten['consumed'][0]['quantity']);
        self::assertSame('slice', $eaten['consumed'][0]['unit']);
        self::assertSame('on_hand', $eaten['consumed'][0]['status']);

        $split = $this->svc->pantry->splitOrMove($this->user, $lot['id'], [
            'pieces' => [
                ['quantity' => 2, 'location' => 'fridge'],
                ['quantity' => 2, 'location' => 'freezer'],
            ],
        ], 'david');
        self::assertSame('used', $split['parent']['status']);
        self::assertEquals(0.0, $split['parent']['quantity']);
        self::assertCount(2, $split['lots']);
        $byPlace = [];
        foreach ($split['lots'] as $child) {
            $byPlace[$child['location']] = $child;
            self::assertSame($bread['id'], $child['product_id']);
            self::assertSame('slice', $child['unit']);
            self::assertSame('2026-09-01', $child['purchased_at']);
            self::assertSame($lot['id'], $child['parent_lot_id']);
            self::assertFalse($child['use_by_overridden']);
        }
        self::assertEquals(2.0, $byPlace['fridge']['quantity']);
        self::assertSame('2026-09-08', $byPlace['fridge']['use_by']);
        self::assertEquals(2.0, $byPlace['freezer']['quantity']);
        self::assertSame('2026-12-24', $byPlace['freezer']['use_by']);
    }

    public function testFractionalConsumeLeavesHalfThenUsesTheLot(): void
    {
        $product = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Apple',
            'category' => 'Produce',
            'default_unit' => 'each',
        ]);
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 1,
            'unit' => 'each',
            'location' => 'pantry',
        ]], 'david')['lots'][0];

        $half = $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id'], 'quantity' => 0.5], 'david');
        self::assertSame('on_hand', $half['consumed'][0]['status']);
        self::assertEquals(0.5, $half['consumed'][0]['quantity']);

        $rest = $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id'], 'quantity' => 0.5], 'david');
        self::assertSame('used', $rest['consumed'][0]['status']);
        self::assertEquals(0.0, $rest['consumed'][0]['quantity']);
    }

    public function testAdjustReopensAUsedLotAndRefusesATossedOne(): void
    {
        $product = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Yogurt',
            'category' => 'Dairy',
            'default_unit' => 'each',
        ]);
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $product['id'],
            'quantity' => 1,
            'unit' => 'each',
            'location' => 'fridge',
        ]], 'david')['lots'][0];
        $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id'], 'quantity' => 1], 'david');

        $reopened = $this->svc->pantry->adjust($this->user, $lot['id'], ['quantity' => 0.5], 'david')['lot'];
        self::assertSame('on_hand', $reopened['status']);
        self::assertEquals(0.5, $reopened['quantity']);

        $this->svc->pantry->toss($this->user, [$lot['id']], 'david');
        try {
            $this->svc->pantry->adjust($this->user, $lot['id'], ['quantity' => 1], 'david');
            self::fail('expected a tossed lot to stay closed');
        } catch (ValidationException $e) {
            self::assertStringContainsString('tossed', $e->fields()['lot_id']);
        }
    }

    public function testUpdateRecipeReplacesNameNotesAndIngredients(): void
    {
        $onion = $this->svc->pantry->upsertProduct($this->user, ['name' => 'Onion', 'category' => 'Produce']);
        $created = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Soup',
            'notes' => 'Boil',
            'ingredients' => [['product_id' => $onion['id'], 'quantity' => 1, 'unit' => 'each']],
        ]);
        $updated = $this->svc->pantry->upsertRecipe($this->user, [
            'recipe_id' => $created['id'],
            'name' => 'Onion soup',
            'notes' => 'Simmer',
            'ingredients' => [
                ['product_id' => $onion['id'], 'quantity' => 0.5, 'unit' => 'lb'],
                ['category' => 'Produce'],
            ],
        ]);
        self::assertSame($created['id'], $updated['id']);
        self::assertSame('Onion soup', $updated['name']);
        self::assertSame('Simmer', $updated['notes']);
        self::assertCount(2, $updated['ingredients']);
        self::assertEquals(0.5, $updated['ingredients'][0]['quantity']);
        self::assertSame('lb', $updated['ingredients'][0]['unit']);
        self::assertSame('Produce', $updated['ingredients'][1]['category']);
        self::assertNull($updated['ingredients'][1]['product_id']);
        self::assertNull($updated['servings']);
        self::assertSame(3, $updated['keeps_days']);
        self::assertFalse($updated['meal_prep']);
        self::assertNull($updated['protein_g']);
    }

    public function testRecipePrepFieldsFilterAndStayWhenOmitted(): void
    {
        $created = $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Soup',
            'meal_prep' => true,
            'servings' => 2,
            'keeps_days' => 4,
            'protein_g' => 18.2,
            'ingredients' => [],
        ]);
        self::assertSame(2, $created['servings']);
        self::assertSame(4, $created['keeps_days']);
        self::assertTrue($created['meal_prep']);
        self::assertEquals(18.2, $created['protein_g']);

        $kept = $this->svc->pantry->upsertRecipe($this->user, [
            'recipe_id' => $created['id'],
            'name' => 'Soup',
            'ingredients' => [],
        ]);
        self::assertSame(2, $kept['servings']);
        self::assertSame(4, $kept['keeps_days']);
        self::assertTrue($kept['meal_prep']);
        self::assertEquals(18.2, $kept['protein_g']);

        $cleared = $this->svc->pantry->upsertRecipe($this->user, [
            'recipe_id' => $created['id'],
            'name' => 'Soup',
            'servings' => null,
            'protein_g' => null,
            'meal_prep' => false,
            'ingredients' => [],
        ]);
        self::assertNull($cleared['servings']);
        self::assertNull($cleared['protein_g']);
        self::assertFalse($cleared['meal_prep']);
        self::assertSame(4, $cleared['keeps_days']);

        $other = $this->svc->pantry->upsertRecipe($this->user, ['name' => 'Snack', 'ingredients' => []]);
        $only = $this->svc->pantry->listRecipes($this->user, true);
        self::assertSame([], array_column($only, 'name'));
        $this->svc->pantry->upsertRecipe($this->user, [
            'recipe_id' => $created['id'],
            'name' => 'Soup',
            'meal_prep' => true,
            'ingredients' => [],
        ]);
        $prep = array_column($this->svc->pantry->listRecipes($this->user, true), 'name');
        $rest = array_column($this->svc->pantry->listRecipes($this->user, false), 'name');
        self::assertSame(['Soup'], $prep);
        self::assertContains('Snack', $rest);
        self::assertNotContains('Soup', $rest);
        self::assertSame($other['name'], 'Snack');
    }

    public function testOpenAndResetUseTheEarlierOfOpenedAndLocationLife(): void
    {
        $milk = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Milk',
            'category' => 'Dairy',
            'default_unit' => 'each',
            'shelf_life_fridge_days' => 90,
            'shelf_life_freezer_days' => 90,
            'opened_life_days' => 3,
        ]);
        $stale = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $milk['id'],
            'quantity' => 2,
            'location' => 'fridge',
            'purchased_at' => '2026-09-01',
            'use_by' => '2026-09-20',
        ]], 'david')['lots'][0];
        $opened = $this->svc->pantry->openLot($this->user, $stale['id'], 'david')['lot'];
        self::assertSame('2026-09-28', $opened['use_by']);
        self::assertFalse($opened['use_by_overridden']);
        self::assertNotNull($opened['opened_at']);

        $reset = $this->svc->pantry->adjust($this->user, $stale['id'], ['reset_use_by' => true], 'david')['lot'];
        self::assertSame('2026-09-28', $reset['use_by']);
        self::assertNotNull($reset['opened_at']);

        $frozen = $this->svc->pantry->adjust($this->user, $stale['id'], [
            'location' => 'freezer',
            'reset_use_by' => true,
        ], 'david')['lot'];
        self::assertSame('freezer', $frozen['location']);
        self::assertSame('2026-09-28', $frozen['use_by']);
        self::assertNotNull($frozen['opened_at']);

        $splitSource = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $milk['id'],
            'quantity' => 2,
            'location' => 'fridge',
            'purchased_at' => '2026-09-01',
        ]], 'david')['lots'][0];
        $this->svc->pantry->openLot($this->user, $splitSource['id'], 'david');
        $split = $this->svc->pantry->splitOrMove($this->user, $splitSource['id'], [
            'pieces' => [
                ['quantity' => 1, 'location' => 'fridge'],
                ['quantity' => 1, 'location' => 'freezer'],
            ],
        ], 'david');
        $byPlace = [];
        foreach ($split['lots'] as $child) {
            $byPlace[$child['location']] = $child;
            self::assertNotNull($child['opened_at']);
        }
        self::assertSame('2026-09-28', $byPlace['fridge']['use_by']);
        self::assertSame('2026-09-28', $byPlace['freezer']['use_by']);

        $seeded = null;
        foreach ($this->svc->pantry->listProducts($this->user, 'Onion') as $product) {
            if ($product['name'] === 'Onion' && $product['source'] === 'produce') {
                $seeded = $product;
            }
        }
        self::assertNotNull($seeded);
        $onion = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $seeded['id'],
            'quantity' => 1,
            'location' => 'fridge',
            'purchased_at' => '2026-09-01',
            'use_by' => '2026-09-20',
        ]], 'david')['lots'][0];
        $recomputed = $this->svc->pantry->openLot($this->user, $onion['id'], 'david')['lot'];
        self::assertSame('2026-10-31', $recomputed['use_by']);
    }

    public function testCategoryDefaultsUpdateOnlyOldSeedsAndShelfEditsValidate(): void
    {
        $by = [];
        foreach ($this->svc->pantry->categories($this->user) as $row) {
            $by[$row['category']] = $row;
        }
        self::assertSame(21, $by['Dairy']['fridge_days']);
        self::assertSame(30, $by['Other']['fridge_days']);
        self::assertSame(7, $by['Bakery']['pantry_days']);
        self::assertSame(7, $by['Produce']['fridge_days']);
        self::assertSame(7, $by['Produce']['pantry_days']);

        $this->db->execute("UPDATE category_shelf_life SET fridge_days = 7 WHERE user_id = ? AND category = 'Dairy'", [$this->user]);
        $this->db->execute("UPDATE category_shelf_life SET fridge_days = 7 WHERE user_id = ? AND category = 'Other'", [$this->user]);
        $this->db->execute("UPDATE category_shelf_life SET pantry_days = 5 WHERE user_id = ? AND category = 'Bakery'", [$this->user]);
        $this->db->execute("UPDATE category_shelf_life SET fridge_days = 14 WHERE user_id = ? AND category = 'Produce'", [$this->user]);
        $this->db->execute("UPDATE category_shelf_life SET fridge_days = 10 WHERE user_id = ? AND category = 'Meat'", [$this->user]);
        $sql = file_get_contents(dirname(__DIR__, 2) . '/database/pgsql/0028_pantry_shelf_defaults.sql');
        self::assertNotFalse($sql);
        $this->db->pdo()->exec((string) $sql);

        $again = [];
        foreach ($this->svc->pantry->categories($this->user) as $row) {
            $again[$row['category']] = $row;
        }
        self::assertSame(21, $again['Dairy']['fridge_days']);
        self::assertSame(7, $again['Dairy']['opened_days']);
        self::assertSame(30, $again['Other']['fridge_days']);
        self::assertSame(7, $again['Bakery']['pantry_days']);
        self::assertSame(7, $again['Bakery']['fridge_days']);
        self::assertSame(14, $again['Produce']['fridge_days']);
        self::assertSame(10, $again['Meat']['fridge_days']);

        $saved = $this->svc->pantry->updateCategory($this->user, 'Dairy', ['fridge_days' => 18, 'opened_days' => null]);
        self::assertSame(18, $saved['fridge_days']);
        self::assertNull($saved['opened_days']);
        self::assertNull($saved['pantry_days']);
        foreach ([0, 3651, 1.5] as $bad) {
            try {
                $this->svc->pantry->updateCategory($this->user, 'Dairy', ['fridge_days' => $bad]);
                self::fail('expected days ' . $bad . ' to be rejected');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('fridge_days', $e->fields());
            }
        }

        $onion = null;
        foreach ($this->svc->pantry->listProducts($this->user, 'Onion') as $product) {
            if ($product['name'] === 'Onion') {
                $onion = $product;
            }
        }
        self::assertNotNull($onion);
        $tuned = $this->svc->pantry->updateProductShelf($this->user, (int) $onion['id'], [
            'shelf_life_fridge_days' => 9,
            'opened_life_days' => null,
        ]);
        self::assertSame(9, $tuned['shelf_life_fridge_days']);
        self::assertNull($tuned['opened_life_days']);
        self::assertSame(30, $tuned['shelf_life_pantry_days']);
        try {
            $this->svc->pantry->updateProductShelf($this->user, (int) $onion['id'], ['shelf_life_fridge_days' => 0]);
            self::fail('expected a zero shelf life to be rejected');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('shelf_life_fridge_days', $e->fields());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sausage(): array
    {
        return $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Johnsonville sausage',
            'category' => 'Meat',
            'default_unit' => 'each',
            'shelf_life_fridge_days' => 3,
            'shelf_life_freezer_days' => 90,
            'opened_life_days' => 2,
        ]);
    }
}
