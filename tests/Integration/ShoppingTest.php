<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class ShoppingTest extends PgsqlTestCase
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

    public function testListsItemsDedupeCheckAndClear(): void
    {
        $lists = $this->svc->shopping->lists($this->user);
        self::assertSame('Groceries', $lists[0]['name']);

        $costco = $this->svc->shopping->createList($this->user, 'Costco');
        $again = $this->svc->shopping->createList($this->user, 'costco');
        self::assertSame($costco['id'], $again['id']);

        $groceries = $lists[0]['id'];
        $added = $this->svc->shopping->addItems($this->user, $groceries, ['2 lb chicken', 'milk', '2 lb chicken'], 'agent');
        self::assertFalse($added[0]['merged']);
        self::assertSame('Meat', $added[0]['category']);
        self::assertSame('agent', $added[0]['added_by']);
        self::assertTrue($added[2]['merged']);
        self::assertEquals(4.0, $added[2]['quantity']);

        $open = $this->svc->shopping->items($this->user, $groceries, false, 24);
        self::assertCount(2, $open);

        $bought = $this->svc->shopping->checkItem($this->user, $added[0]['id'], true);
        self::assertTrue($bought['bought']);
        self::assertNotNull($bought['bought_at']);

        $board = $this->svc->shopping->board($this->user, 0, 24);
        $g = $this->listNamed($board['lists'], 'Groceries');
        self::assertSame(['Dairy'], array_column($g['groups'], 'category'));
        self::assertCount(1, $g['bought']);
        self::assertSame(1, $this->svc->shopping->clearBought($this->user, $groceries));
        $left = $this->svc->shopping->items($this->user, $groceries, true, 0);
        self::assertCount(1, $left);
        self::assertSame('milk', $left[0]['name']);
    }

    public function testBoughtRowsExpireAfterTheConfiguredHours(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $item = $this->svc->shopping->addItems($this->user, $listId, [['name' => 'eggs']], 'user')[0];
        $this->svc->shopping->checkItem($this->user, $item['id'], true);
        $this->db->execute(
            'UPDATE shopping_items SET bought_at = ? WHERE id = ?',
            ['2026-09-24 10:00:00', $item['id']]
        );
        self::assertSame([], $this->svc->shopping->items($this->user, $listId, true, 24));
        $fresh = $this->svc->shopping->addItems($this->user, $listId, ['bread'], 'user')[0];
        $this->svc->shopping->checkItem($this->user, $fresh['id'], true);
        self::assertCount(1, $this->svc->shopping->items($this->user, $listId, true, 24));
    }

    public function testOtherUserCannotSeeTheList(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $this->db->execute("INSERT INTO users (username, email, password_hash) VALUES ('ada', 'a@x', 'h')");
        $other = (int) $this->db->lastInsertId();
        $this->expectException(NotFoundException::class);
        $this->svc->shopping->addItems($other, $listId, ['tape'], 'user');
    }

    public function testWidgetPayloadGroupsTheChosenList(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $this->svc->shopping->addItems($this->user, $listId, ['apples', 'soap'], 'user');
        $widget = $this->svc->widgets->create($this->user, 'shopping', 'Shopping', ['clear_hours' => 12]);
        $data = $this->svc->widgetData->forWidget($this->user, $widget['id']);
        self::assertSame([], $data['metric_ids']);
        self::assertSame(12, $data['clear_hours']);
        self::assertSame(['Produce', 'Household'], array_column($data['lists'][0]['groups'], 'category'));
    }

    public function testRejectsAnUnknownAisle(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $this->expectException(ValidationException::class);
        $this->svc->shopping->addItems($this->user, $listId, [['name' => 'tape', 'category' => 'Aisle 9']], 'user');
    }

    public function testDedupeKeyUpdatesTheOpenItemAndKeepsItsNotes(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $first = $this->svc->shopping->addItems($this->user, $listId, [[
            'name' => 'Shrimp',
            'quantity' => 1,
            'source' => 'ai',
            'dedupe_key' => 'shrimp-raw-16oz',
            'notes' => 'keep me',
            'needed_by' => '2026-11-04',
            'order_wave' => 'homecoming Nov 4',
        ]], 'agent')[0];
        self::assertFalse($first['merged']);
        self::assertSame('ai', $first['source']);
        self::assertSame('shrimp-raw-16oz', $first['dedupe_key']);
        self::assertSame('2026-11-04', $first['needed_by']);
        self::assertSame('homecoming Nov 4', $first['order_wave']);

        $again = $this->svc->shopping->addItems($this->user, $listId, [[
            'name' => 'Large shrimp',
            'quantity' => 2,
            'dedupe_key' => 'shrimp-raw-16oz',
            'notes' => 'new note',
        ]], 'agent')[0];
        self::assertTrue($again['merged']);
        self::assertSame($first['id'], $again['id']);
        self::assertSame('Large shrimp', $again['name']);
        self::assertEquals(3.0, $again['quantity']);
        self::assertSame('keep me', $again['notes']);
        self::assertSame('2026-11-04', $again['needed_by']);
        self::assertCount(1, $this->svc->shopping->items($this->user, $listId, false, 0));
    }

    public function testSameNameAdoptsAKeyAndADifferentKeyIsRejected(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $this->svc->shopping->addItems($this->user, $listId, [['name' => 'Milk']], 'user');
        $merged = $this->svc->shopping->addItems($this->user, $listId, [[
            'name' => 'Milk',
            'dedupe_key' => 'store-milk-gallon',
        ]], 'user')[0];
        self::assertTrue($merged['merged']);
        self::assertSame('store-milk-gallon', $merged['dedupe_key']);
        self::assertEquals(2.0, $merged['quantity']);

        try {
            $this->svc->shopping->addItems($this->user, $listId, [[
                'name' => 'Milk',
                'dedupe_key' => 'other-milk-key',
            ]], 'user');
            self::fail('a second key must not replace the open item');
        } catch (ValidationException $e) {
            self::assertStringContainsString('different dedupe_key', $e->getMessage());
        }
    }

    public function testDeferredWaveStaysOutOfTheNextCartAndTheWidget(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $this->svc->shopping->addItems($this->user, $listId, [
            ['name' => 'Bananas', 'needed_by' => '2026-11-04', 'order_wave' => 'homecoming Nov 4'],
            ['name' => 'Flour', 'order_wave' => 'Deferred'],
            ['name' => 'Rice'],
        ], 'agent');

        $cart = $this->svc->shopping->items($this->user, $listId, false, 0, ['include_deferred' => false]);
        self::assertEqualsCanonicalizing(['Bananas', 'Rice'], array_column($cart, 'name'));

        $wave = $this->svc->shopping->items($this->user, $listId, false, 0, [
            'include_deferred' => false,
            'order_wave' => 'deferred',
        ]);
        self::assertSame(['Flour'], array_column($wave, 'name'));

        $byDate = $this->svc->shopping->items($this->user, $listId, false, 0, [
            'include_deferred' => true,
            'needed_before' => '2026-11-04',
        ]);
        self::assertSame(['Bananas'], array_column($byDate, 'name'));

        $board = $this->svc->shopping->board($this->user, $listId, 0);
        self::assertSame(2, $board['lists'][0]['open_count']);
        self::assertSame(['Flour'], array_column($board['lists'][0]['deferred'], 'name'));
        self::assertSame(2, $this->svc->shopping->lists($this->user)[0]['open_count']);

        $cleared = $this->svc->shopping->updateItem($this->user, $board['lists'][0]['deferred'][0]['id'], [
            'order_wave' => '',
            'needed_by' => '2026-12-01',
        ]);
        self::assertNull($cleared['order_wave']);
        self::assertSame('2026-12-01', $cleared['needed_by']);
    }

    public function testUncheckingMergesOntoTheOpenDedupeKey(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $bought = $this->svc->shopping->addItems($this->user, $listId, [[
            'name' => 'Pasta',
            'quantity' => 1,
            'dedupe_key' => 'angel-hair-16oz',
        ]], 'user')[0];
        $this->svc->shopping->checkItem($this->user, $bought['id'], true);
        $open = $this->svc->shopping->addItems($this->user, $listId, [[
            'name' => 'Angel hair',
            'quantity' => 2,
            'dedupe_key' => 'angel-hair-16oz',
        ]], 'user')[0];
        self::assertNotSame($bought['id'], $open['id']);
        $back = $this->svc->shopping->checkItem($this->user, $bought['id'], false);
        self::assertSame($open['id'], $back['id']);
        self::assertEquals(3.0, $back['quantity']);
        self::assertCount(1, $this->svc->shopping->items($this->user, $listId, false, 0));
    }

    public function testNoteKeysMoveIntoColumnsWithoutRewritingNotes(): void
    {
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $rows = [
            'clear' => "source: ai\nwf-la-fede-san-marzano-dop-28oz",
            'comma' => 'Source: AI, wf-cento-san-marzano-dop-28oz',
            'label' => 'dedupe_key: angel-hair-16oz',
            'source' => 'source: ai',
            'slug' => 'wf-only-key-here',
            'prose' => 'Remember source: ai and wf-la-fede-san-marzano-dop-28oz later',
            'plain' => 'buy milk',
        ];
        $ids = [];
        foreach ($rows as $name => $notes) {
            $ids[$name] = (int) $this->db->fetchScalar(
                'INSERT INTO shopping_items (list_id, user_id, name, name_key, notes)
                 VALUES (?, ?, ?, ?, ?) RETURNING id',
                [$listId, $this->user, $name, $name, $notes]
            );
        }
        $dupA = (int) $this->db->fetchScalar(
            'INSERT INTO shopping_items (list_id, user_id, name, name_key, notes)
             VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$listId, $this->user, 'dup a', 'dup a', 'wf-same-key-twice']
        );
        $dupB = (int) $this->db->fetchScalar(
            'INSERT INTO shopping_items (list_id, user_id, name, name_key, notes)
             VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$listId, $this->user, 'dup b', 'dup b', 'wf-same-key-twice']
        );
        $this->rerunNoteBackfill();

        $clear = $this->noteRow($ids['clear']);
        self::assertSame('ai', $clear['source']);
        self::assertSame('wf-la-fede-san-marzano-dop-28oz', $clear['dedupe_key']);
        self::assertSame($rows['clear'], $clear['notes']);

        $comma = $this->noteRow($ids['comma']);
        self::assertSame('ai', $comma['source']);
        self::assertSame('wf-cento-san-marzano-dop-28oz', $comma['dedupe_key']);
        self::assertSame($rows['comma'], $comma['notes']);

        $label = $this->noteRow($ids['label']);
        self::assertNull($label['source']);
        self::assertSame('angel-hair-16oz', $label['dedupe_key']);

        $source = $this->noteRow($ids['source']);
        self::assertSame('ai', $source['source']);
        self::assertNull($source['dedupe_key']);

        $slug = $this->noteRow($ids['slug']);
        self::assertNull($slug['source']);
        self::assertSame('wf-only-key-here', $slug['dedupe_key']);

        foreach (['prose', 'plain'] as $name) {
            $row = $this->noteRow($ids[$name]);
            self::assertNull($row['source']);
            self::assertNull($row['dedupe_key']);
            self::assertSame($rows[$name], $row['notes']);
        }
        self::assertNull($this->noteRow($dupA)['dedupe_key']);
        self::assertNull($this->noteRow($dupB)['dedupe_key']);
    }

    /**
     * @return array<string, mixed>
     */
    private function noteRow(int $id): array
    {
        $row = $this->db->fetchOne(
            'SELECT source, dedupe_key, notes FROM shopping_items WHERE id = ? AND user_id = ?',
            [$id, $this->user]
        );
        self::assertNotNull($row);
        return $row;
    }

    private function rerunNoteBackfill(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/pgsql/0059_shopping_cart.sql');
        $start = strpos($sql, 'WITH trimmed AS');
        $end = strpos($sql, 'CREATE UNIQUE INDEX');
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $update = rtrim(trim(substr($sql, (int) $start, (int) $end - (int) $start)), ';');
        $this->db->execute($update);
    }

    /**
     * @param list<array<string, mixed>> $lists
     * @return array<string, mixed>
     */
    private function listNamed(array $lists, string $name): array
    {
        foreach ($lists as $list) {
            if ($list['name'] === $name) {
                return $list;
            }
        }
        self::fail('missing list ' . $name);
    }
}
