<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Auth\Auth;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

final class HabitAdminTest extends PgsqlTestCase
{
    private Services $svc;

    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['david', 'david@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services(
            $this->db,
            new Clock('America/Chicago', new \DateTimeImmutable('2026-10-15 15:00:00', new \DateTimeZone('UTC')))
        );
    }

    protected function tearDown(): void
    {
        putenv('LIFEDASH_TEST_CAPTURE');
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LIFEDASH_TOOL'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'], $_GET);
        parent::tearDown();
    }

    public function testCreateIsIdempotentOnKeyAndDefaultsToDaily(): void
    {
        $water = $this->svc->habits->create($this->user, [
            'name' => 'Water', 'slug' => 'water', 'icon' => '💧', 'color' => '#3D8BFD',
        ], 'ai');
        self::assertTrue($water['created']);
        self::assertSame('water', $water['key']);
        self::assertSame('💧', $water['icon']);
        self::assertSame('#3d8bfd', $water['color']);
        self::assertSame('daily', $water['frequency']);
        self::assertSame(7, $water['target_per_week']);
        self::assertSame('ai', $this->svc->metrics->get($this->user, $water['id'])['created_by']);

        $again = $this->svc->habits->create($this->user, [
            'name' => 'Different', 'key' => 'water', 'icon' => '🔥',
        ]);
        self::assertFalse($again['created']);
        self::assertSame($water['id'], $again['id']);
        self::assertSame('Water', $again['name']);
        self::assertSame('💧', $again['icon']);

        $named = $this->svc->habits->create($this->user, ['name' => 'Stretch']);
        self::assertSame('stretch', $named['key']);
        self::assertTrue($named['created']);
    }

    public function testUpdateArchiveUnarchiveAndReorder(): void
    {
        $lunch = $this->svc->habits->create($this->user, ['name' => 'Lunch', 'key' => 'lunch', 'sort_order' => 2]);
        $dinner = $this->svc->habits->create($this->user, ['name' => 'Dinner', 'key' => 'dinner', 'frequency' => 'weekdays']);
        $move = $this->svc->habits->create($this->user, ['name' => 'Movement', 'key' => 'movement', 'target' => 4]);
        self::assertSame(2, $lunch['sort_order']);
        self::assertSame('weekdays', $dinner['frequency']);
        self::assertSame(4, $move['target_per_week']);

        $renamed = $this->svc->habits->update($this->user, $lunch['id'], [
            'name' => 'Lunch out', 'color' => '#112233',
        ]);
        self::assertSame('Lunch out', $renamed['name']);
        self::assertSame('#112233', $renamed['color']);
        self::assertSame('daily', $renamed['frequency']);

        try {
            $this->svc->habits->update($this->user, $lunch['id'], ['key' => 'brunch']);
            self::fail('key change accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('key', $e->fields());
        }

        $hidden = $this->svc->habits->setArchived($this->user, $dinner['id'], true);
        self::assertTrue($hidden['archived']);
        $keys = array_column($this->svc->habits->summary($this->user)['habits'], 'key');
        self::assertNotContains('dinner', $keys);
        $back = $this->svc->habits->setArchived($this->user, $dinner['id'], false);
        self::assertFalse($back['archived']);
        $keys = array_column($this->svc->habits->summary($this->user)['habits'], 'key');
        self::assertContains('dinner', $keys);

        $ordered = $this->svc->habits->reorder($this->user, [$move['id'], $lunch['id'], $dinner['id']]);
        self::assertSame(
            ['movement', 'lunch', 'dinner'],
            array_column($ordered['habits'], 'key')
        );
    }

    public function testKeyOwnedByAnotherKindIsRejected(): void
    {
        $this->svc->metrics->create($this->user, ['name' => 'Steps', 'kind' => 'number', 'key' => 'steps']);
        try {
            $this->svc->habits->create($this->user, ['name' => 'Steps', 'key' => 'steps']);
            self::fail('number key reused as a habit');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('key', $e->fields());
        }
    }

    public function testAiCreateIsAuditedWithoutTheHabitName(): void
    {
        $habit = $this->svc->habits->create($this->user, ['name' => 'Water', 'key' => 'water']);
        $token = $this->svc->tokens->create($this->user, 'Household', ['metrics:read', 'metrics:write']);
        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token['token'];
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'create_habit';
        $_SERVER['REQUEST_URI'] = '/api/v1/habits';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        putenv('LIFEDASH_TEST_CAPTURE=1');
        try {
            $runner->run(static fn (int $u): array => ['habit' => $habit], 200, true);
            self::fail('expected a captured response');
        } catch (CapturedResponse $e) {
            self::assertSame(200, $e->status);
        }

        $events = $this->svc->audit->search($this->user, [
            'entity' => 'habit',
            'entity_id' => $habit['id'],
            'tool' => 'create_habit',
            'actor' => 'ai',
        ], Scope::auditEntityAllow($token['scopes'], 'habit'));
        self::assertNotSame([], $events);
        self::assertSame('/api/v1/habits', $events[0]['resource']);
        self::assertSame('create_habit', $events[0]['tool']);
        self::assertSame('habit', $events[0]['detail']['entity']);
        self::assertSame('Household', $events[0]['detail']['token_name']);
        $blob = json_encode($events[0], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Water', $blob);
    }
}
