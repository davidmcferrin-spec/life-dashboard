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
