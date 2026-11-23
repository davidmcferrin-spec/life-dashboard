<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\BoardApi;
use Dashboard\Auth\Auth;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

final class BoardTest extends PgsqlTestCase
{
    private int $user;

    private int $other;

    private Services $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['david', 'david@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['bea', 'bea@x', 'h']
        );
        $this->other = (int) $this->db->lastInsertId();
        $this->svc = new Services(
            $this->db,
            new Clock('America/Chicago', new \DateTimeImmutable('2026-11-23 16:00:00', new \DateTimeZone('UTC')))
        );
    }

    protected function tearDown(): void
    {
        putenv('LIFEDASH_TEST_CAPTURE');
        unset($_GET, $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LIFEDASH_TOOL'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    public function testBriefTripVideoFindingAndDialStayPerUser(): void
    {
        $page = $this->svc->pages->create($this->user, ['title' => 'Space Desk — Newsletter Index', 'body' => 'Index']);
        $published = $this->svc->briefsDigest->publish($this->user, [
            'source' => 'Space Desk',
            'title' => 'Monday brief',
            'date' => '2026-11-23',
            'items' => ['Launch window', 'Crew note', 'Weather'],
            'page_id' => $page['id'],
        ]);
        $again = $this->svc->briefsDigest->publish($this->user, [
            'source' => 'space desk',
            'title' => 'Monday brief',
            'date' => '2026-11-23',
            'items' => ['Launch window', 'Updated'],
        ]);
        self::assertSame($published['digest']['id'], $again['digest']['id']);
        self::assertSame(['Launch window', 'Updated'], $again['digest']['items']);
        self::assertSame((int) $page['id'], $again['digest']['page_id']);
        $this->svc->briefsDigest->publish($this->other, [
            'source' => 'Space Desk',
            'title' => 'Bea brief',
            'date' => '2026-11-23',
            'items' => ['Hidden'],
        ]);
        $widget = $this->svc->widgets->create($this->user, 'briefs', 'Briefs', ['source' => 'Space Desk']);
        $card = $this->svc->widgetData->forWidget($this->user, (int) $widget['id']);
        self::assertSame('Monday brief', $card['brief']['title']);
        self::assertSame(['Launch window', 'Updated'], $card['brief']['items']);
        self::assertStringContainsString('page=' . $page['id'], (string) $card['brief']['url']);

        $trip = $this->svc->trips->create($this->user, [
            'name' => 'Disney week',
            'destination' => 'Orlando',
            'start_date' => '2026-12-01',
            'end_date' => '2026-12-05',
            'status' => 'planning',
            'owner_bot' => 'Imagineer',
            'tags' => ['disney'],
            'template' => true,
        ], 'Imagineer', true);
        $cardTrip = $trip['trip'];
        self::assertNotNull($cardTrip['page_id']);
        self::assertSame('Imagineer', $cardTrip['owner_bot']);
        $body = (string) $this->svc->pages->get($this->user, (int) $cardTrip['page_id'])['body'];
        foreach (['# Overview', '# Dates', '# Flights', '# Lodging', '# Budget', '# Packing', '# To-dos'] as $heading) {
            self::assertStringContainsString($heading, $body);
        }
        $folder = $this->svc->pages->folderContents($this->user, (int) $cardTrip['folder_id']);
        self::assertSame('04 Travel', $folder['folder']['name']);
        $second = $this->svc->trips->update($this->user, (int) $cardTrip['id'], ['template' => true], 'Imagineer', true);
        self::assertSame($cardTrip['page_id'], $second['trip']['page_id']);

        $groceries = $this->svc->shopping->createList($this->user, 'Groceries');
        $list = $this->svc->shopping->addItems($this->user, (int) $groceries['id'], [
            ['name' => 'Sunscreen', 'order_wave' => 'disney', 'trip_id' => $cardTrip['id']],
        ], 'agent');
        $handoff = $this->svc->handoffs->create($this->user, [
            'title' => 'Park hours',
            'tags' => ['disney'],
            'trip_id' => $cardTrip['id'],
        ], 'Imagineer');
        $full = $this->svc->trips->get($this->user, (int) $cardTrip['id']);
        self::assertSame(['Sunscreen'], array_column($full['trip']['shopping_items'], 'name'));
        self::assertSame(['Park hours'], array_column($full['trip']['handoffs'], 'title'));
        self::assertSame((int) $cardTrip['id'], $list[0]['trip_id']);
        self::assertSame((int) $cardTrip['id'], $handoff['handoff']['trip_id']);

        $done = $this->svc->trips->create($this->user, [
            'name' => 'Old sailing',
            'status' => 'done',
            'end_date' => '2026-01-01',
            'owner_bot' => 'Sailing Trip Research',
        ], 'Sailing Trip Research', true);
        $upcoming = $this->svc->trips->widget($this->user);
        self::assertSame(['Disney week'], array_column($upcoming['trips'], 'name'));
        self::assertNotContains('Old sailing', array_column($upcoming['trips'], 'name'));
        try {
            $this->svc->trips->get($this->other, (int) $cardTrip['id']);
            self::fail('expected another user to miss the trip');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
        try {
            $this->svc->trips->update($this->user, (int) $cardTrip['id'], [
                'page_ids' => [(int) $this->svc->pages->create($this->other, ['title' => 'Bea page', 'body' => ''])['id']],
            ], 'Imagineer', true);
            self::fail('expected a foreign page to be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('page_ids', $e->fields());
        }
        self::assertSame('done', $done['trip']['status']);

        $video = $this->svc->videos->add($this->user, [
            'channel' => 'Disney trivia',
            'title' => 'Hidden Mickeys',
            'status' => 'idea',
            'tags' => ['disney'],
        ]);
        $moved = $this->svc->videos->update($this->user, (int) $video['video']['id'], ['status' => 'voiced']);
        self::assertSame('voiced', $moved['video']['status']);
        self::assertSame([], $this->svc->videos->list($this->other, ['channel' => 'Disney trivia'])['videos']);

        $notes = $this->svc->pages->create($this->user, ['title' => 'NY move notes', 'body' => 'Start']);
        $finding = $this->svc->findings->log($this->user, [
            'topic' => 'housing',
            'title' => 'Lease comparison',
            'summary' => 'Two buildings',
            'url' => 'https://example.com/lease',
            'tags' => ['nyc'],
            'page_title' => 'NY move notes',
            'append' => true,
        ], 'NY Move Research', true);
        self::assertSame((int) $notes['id'], $finding['finding']['page_id']);
        $appended = (string) $this->svc->pages->get($this->user, (int) $notes['id'])['body'];
        self::assertStringContainsString('Lease comparison', $appended);
        self::assertStringContainsString('https://example.com/lease', $appended);
        self::assertStringStartsWith('Start', $appended);
        self::assertSame([], $this->svc->findings->list($this->other, ['topic' => 'housing'])['findings']);

        $contact = $this->svc->calls->addContact($this->user, [
            'name' => 'Front desk',
            'phones' => ['2565550199'],
            'emails' => ['desk@example.com'],
            'org' => 'Hotel',
            'tags' => ['travel'],
        ]);
        $call = $this->svc->calls->log($this->user, [
            'to_number' => '2565550199',
            'from_number' => '2565550100',
            'purpose' => 'Confirm the reservation',
            'started_at' => '2026-11-23 10:00:00',
            'duration' => 90,
            'outcome' => 'answered',
            'summary' => 'They have the room.',
            'transcript_url' => 'https://example.com/transcript',
            'placed_by' => 'Dial',
            'contact_id' => $contact['id'],
        ], 'ai');
        self::assertSame('outbound', $call['direction']);
        self::assertSame('2565550199', $call['to_number']);
        self::assertSame(90, $call['duration']);
        self::assertSame('Dial', $call['placed_by']);
        self::assertSame('Confirm the reservation', $call['purpose']);
        $found = $this->svc->calls->findContacts($this->user, 'desk@example.com');
        self::assertSame(['Front desk'], array_column($found, 'name'));
        self::assertSame([], $this->svc->calls->findContacts($this->other, 'desk@example.com'));
        $updated = $this->svc->calls->updateContact($this->user, (int) $contact['id'], ['org' => 'Resort']);
        self::assertSame('Resort', $updated['org']);
        self::assertSame(['travel'], $updated['tags']);
    }

    public function testDigestWriteIsAuditedAndScoped(): void
    {
        $token = $this->svc->tokens->create($this->user, 'Space Desk', ['digest:read', 'digest:write']);
        $narrow = $this->svc->tokens->create($this->user, 'Trips only', ['trips:read']);
        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        $api = new BoardApi($runner, $this->svc);

        $denied = $this->call($runner, $narrow['token'], 'POST', '/api/v1/digests', true, fn (): array => ['digest' => ['id' => 1]]);
        self::assertSame(403, $denied->status);

        $created = $this->call($runner, $token['token'], 'POST', '/api/v1/digests', true, fn (int $u) => $this->svc->briefsDigest->publish($u, [
            'source' => 'Space Desk',
            'title' => 'Audited',
            'date' => '2026-11-23',
            'items' => ['One headline'],
        ]));
        self::assertSame(201, $created->status);
        $id = (int) $created->payload['digest']['id'];
        $events = $this->svc->audit->search($this->user, [
            'entity' => 'news_digest',
            'entity_id' => $id,
            'tool' => 'publish_digest',
        ]);
        self::assertNotSame([], $events);
        self::assertSame('news_digest', $events[0]['detail']['entity']);
        self::assertSame('Space Desk', $events[0]['detail']['token_name']);
        unset($api);
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function call(ApiRunner $runner, string $token, string $method, string $path, bool $mutating, callable $fn): CapturedResponse
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'publish_digest';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        putenv('LIFEDASH_TEST_CAPTURE=1');
        try {
            $runner->run($fn, 201, $mutating);
        } catch (CapturedResponse $response) {
            return $response;
        }
        self::fail('expected a captured response');
    }
}
