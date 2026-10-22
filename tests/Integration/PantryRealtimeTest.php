<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class PantryRealtimeTest extends PgsqlTestCase
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
        $this->svc->pantry->ensureDefaults($this->user);
        $this->svc->shopping->lists($this->user);
    }

    public function testCommittedWritesNotifyAndARollbackDoesNot(): void
    {
        $pdo = $this->db->pdo();
        $pdo->exec('LISTEN lifedash_events');

        $milk = $this->svc->pantry->upsertProduct($this->user, [
            'name' => 'Milk',
            'category' => 'Dairy',
            'default_unit' => 'each',
        ]);
        $lot = $this->svc->pantry->addItems($this->user, [[
            'product_id' => $milk['id'],
            'quantity' => 1,
            'location' => 'fridge',
            'purchased_at' => '2026-09-25',
        ]], 'david')['lots'][0];
        $this->svc->pantry->upsertRecipe($this->user, [
            'name' => 'Milk rice',
            'ingredients' => [['category' => 'Dairy']],
        ]);
        $beef = $this->svc->meals->addComponent($this->user, [
            'name' => 'Ground beef',
            'category' => 'protein',
            'source' => 'david',
        ])['component'];
        $rice = $this->svc->meals->addComponent($this->user, [
            'name' => 'Rice',
            'category' => 'base',
            'source' => 'david',
        ])['component'];
        $this->svc->meals->addPairing($this->user, [
            'component_a_id' => $beef['id'],
            'component_b_id' => $rice['id'],
            'score' => 4,
            'source' => 'david',
        ]);
        $this->svc->meals->addPairing($this->user, [
            'component_a_id' => $beef['id'],
            'component_b_id' => $rice['id'],
            'score' => 5,
            'source' => 'research',
        ]);
        $this->svc->meals->updateComponent($this->user, $rice['id'], ['notes' => 'jasmine']);
        $this->svc->meals->removePairing($this->user, [
            'component_a_id' => $beef['id'],
            'component_b_id' => $rice['id'],
        ]);
        $this->svc->meals->removeComponent($this->user, $rice['id']);
        $listId = $this->svc->shopping->lists($this->user)[0]['id'];
        $this->svc->shopping->addItems($this->user, $listId, ['rice'], 'user');
        $this->svc->pantry->openLot($this->user, $lot['id'], 'david');

        $events = $this->takeNotifications($pdo);
        $pdo->exec('UNLISTEN lifedash_events');

        self::assertSame(1, $this->countOps($events, 'pantry_products', 'insert'));
        self::assertSame(1, $this->countOps($events, 'pantry_lots', 'insert'));
        self::assertSame(1, $this->countOps($events, 'pantry_lots', 'update'));
        self::assertSame(1, $this->countOps($events, 'pantry_recipes', 'insert'));
        self::assertSame(1, $this->countOps($events, 'pantry_recipe_ingredients', 'insert'));
        self::assertSame(2, $this->countOps($events, 'meal_components', 'insert'));
        self::assertSame(1, $this->countOps($events, 'meal_components', 'update'));
        self::assertSame(1, $this->countOps($events, 'meal_components', 'delete'));
        self::assertSame(1, $this->countOps($events, 'meal_pairings', 'insert'));
        self::assertSame(1, $this->countOps($events, 'meal_pairings', 'update'));
        self::assertSame(1, $this->countOps($events, 'meal_pairings', 'delete'));
        self::assertSame(1, $this->countOps($events, 'shopping_items', 'insert'));
        foreach ($events as $event) {
            self::assertSame($this->user, $event['user_id']);
            self::assertIsInt($event['id']);
        }

        $pdo->exec('LISTEN lifedash_events');
        try {
            $this->svc->pantry->consume($this->user, ['lot_id' => $lot['id'], 'quantity' => 5], 'david');
            self::fail('expected a shortfall');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Not enough', $e->fields()['quantity']);
        }
        self::assertSame([], $this->takeNotifications($pdo));
        $pdo->exec('UNLISTEN lifedash_events');
        $left = $this->svc->pantry->listLots($this->user, ['status' => 'on_hand'])['lots'];
        self::assertSame([$lot['id']], array_column($left, 'id'));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function takeNotifications(\PDO $pdo): array
    {
        $seen = [];
        while (($row = $pdo->pgsqlGetNotify(\PDO::FETCH_ASSOC, 200)) !== false) {
            $payload = json_decode($row['payload'], true);
            if (is_array($payload)) {
                $seen[] = $payload;
            }
        }
        return $seen;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function countOps(array $events, string $table, string $op): int
    {
        return count(array_filter(
            $events,
            static fn (array $event): bool => ($event['table'] ?? '') === $table && ($event['op'] ?? '') === $op
        ));
    }
}
