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
