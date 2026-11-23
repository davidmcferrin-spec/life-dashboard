<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\HandoffApi;
use Dashboard\Api\V1\PlatformApi;
use Dashboard\Auth\Auth;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\Services;

final class HandoffTest extends PgsqlTestCase
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
            new Clock('America/Chicago', new \DateTimeImmutable('2026-11-22 18:00:00', new \DateTimeZone('UTC')))
        );
    }

    protected function tearDown(): void
    {
        putenv('LIFEDASH_TEST_CAPTURE');
        unset($_GET, $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LIFEDASH_TOOL'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    public function testBoardThreadIsolationAndWidget(): void
    {
        $page = $this->svc->pages->create($this->user, ['title' => 'Ops note', 'body' => 'Keep']);
        $foreign = $this->svc->pages->create($this->other, ['title' => 'Bea', 'body' => '']);
        try {
            $this->svc->handoffs->create($this->user, [
                'title' => 'Wrong page',
                'linked_page_ids' => [$foreign['id']],
            ], 'Penny');
            self::fail('expected a foreign page to be refused');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('linked_page_ids', $e->fields());
        }

        $created = $this->svc->handoffs->create($this->user, [
            'title' => 'Ship the board',
            'body' => 'Markdown **note**',
            'to_agent' => 'Penny',
            'from_agent' => 'Spoofed',
            'priority' => 'high',
            'due_at' => '2026-11-21',
            'tags' => ['Ops', 'ops'],
            'linked_page_ids' => [$page['id']],
            'linked_urls' => ['https://example.com/ops'],
        ], 'Chief');
        $card = $created['handoff'];
        self::assertSame('Chief', $card['from_agent']);
        self::assertSame('Penny', $card['to_agent']);
        self::assertSame('open', $card['status']);
        self::assertSame(['ops'], $card['tags']);
        self::assertSame([(int) $page['id']], $card['linked_page_ids']);
        self::assertTrue($card['overdue']);
        self::assertSame('created', $created['events'][0]['kind']);
        self::assertSame('Chief', $created['events'][0]['sender']);
        self::assertSame('', $created['events'][0]['body']);

        $later = $this->svc->handoffs->create($this->user, [
            'title' => 'Next week',
            'to_agent' => 'Penny',
            'due_at' => '2026-11-22',
        ], 'Chief');
        self::assertFalse($later['handoff']['overdue']);
        $anyone = $this->svc->handoffs->create($this->user, ['title' => 'Anyone can take this'], 'Chief');
        $this->svc->handoffs->create($this->other, ['title' => 'Bea only', 'to_agent' => 'Penny'], 'Chief');

        $mine = $this->svc->handoffs->list($this->user, ['mine' => true, 'actor' => 'Penny', 'open_only' => true]);
        self::assertSame(['Next week', 'Ship the board'], array_column($mine['handoffs'], 'title'));
        $tagged = $this->svc->handoffs->list($this->user, ['tag' => 'OPS']);
        self::assertSame(['Ship the board'], array_column($tagged['handoffs'], 'title'));
        self::assertSame(['Bea only'], array_column($this->svc->handoffs->list($this->other, ['to_agent' => 'Penny'])['handoffs'], 'title'));

        try {
            $this->svc->handoffs->get($this->other, (int) $card['id']);
            self::fail('expected another user to miss the card');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }

        $comment = $this->svc->handoffs->comment($this->user, (int) $card['id'], 'Penny', 'Looking');
        self::assertSame('open', $comment['handoff']['status']);
        $claimed = $this->svc->handoffs->claim($this->user, (int) $card['id'], 'Penny', 'I have it');
        self::assertSame('claimed', $claimed['handoff']['status']);
        self::assertSame('Penny', $claimed['handoff']['claimed_by']);
        $again = $this->svc->handoffs->claim($this->user, (int) $card['id'], 'Penny');
        self::assertCount(count($claimed['events']), $again['events']);
        $moved = $this->svc->handoffs->updateStatus($this->user, (int) $card['id'], 'blocked', 'Penny', 'Need a login');
        self::assertSame('blocked', $moved['handoff']['status']);
        self::assertSame('Need a login', $moved['events'][array_key_last($moved['events'])]['body']);
        self::assertSame('', $moved['events'][0]['body']);
        $passed = $this->svc->handoffs->reassign($this->user, (int) $card['id'], 'david', 'Chief');
        self::assertSame('Chief', $passed['handoff']['to_agent']);
        self::assertNull($passed['handoff']['claimed_by']);
        self::assertSame('blocked', $passed['handoff']['status']);
        $closed = $this->svc->handoffs->close($this->user, (int) $card['id'], 'Chief', 'done', 'Shipped');
        self::assertSame('done', $closed['handoff']['status']);
        self::assertSame('close', $closed['events'][array_key_last($closed['events'])]['kind']);
        $still = $this->svc->handoffs->close($this->user, (int) $card['id'], 'Chief', 'done');
        self::assertCount(count($closed['events']), $still['events']);
        self::assertFalse($still['handoff']['overdue']);

        $open = $this->svc->handoffs->openForAgent($this->user, 'Penny');
        self::assertSame(['Next week'], array_column($open, 'title'));
        self::assertNotContains('Anyone can take this', array_column($open, 'title'));

        $widget = $this->svc->widgets->create($this->user, 'handoffs', 'Open handoffs', []);
        $payload = $this->svc->widgetData->forWidget($this->user, (int) $widget['id']);
        self::assertSame(2, $payload['open']);
        $byAgent = [];
        foreach ($payload['counts'] as $count) {
            $byAgent[$count['agent']] = $count['count'];
        }
        self::assertSame(['Anyone' => 1, 'Penny' => 1], $byAgent);
        self::assertSame([], $payload['overdue']);
        self::assertSame((int) $anyone['handoff']['id'] > 0, true);
    }

    public function testKeyNameIsTheSenderAndWritesAreAudited(): void
    {
        $token = $this->svc->tokens->create($this->user, 'Penny', ['handoffs:read', 'handoffs:write']);
        $todos = $this->svc->tokens->create($this->user, 'Lists', ['todos:read', 'todos:write']);
        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        $api = new HandoffApi($runner, $this->svc);

        $denied = $this->call($runner, $todos['token'], 'POST', '/api/v1/handoffs', true, fn (): array => ['handoff' => ['id' => 1]]);
        self::assertSame(403, $denied->status);

        $created = $this->call($runner, $token['token'], 'POST', '/api/v1/handoffs', true, fn (int $u) => $api->create($u, [
            'title' => 'From the key',
            'to_agent' => 'Chief',
        ]));
        self::assertSame(201, $created->status);
        self::assertSame('Penny', $created->payload['handoff']['from_agent']);
        $id = (int) $created->payload['handoff']['id'];
        $events = $this->svc->audit->search($this->user, [
            'entity' => 'handoff',
            'entity_id' => $id,
            'tool' => 'create_handoff',
        ]);
        self::assertNotSame([], $events);
        self::assertSame('handoff', $events[0]['detail']['entity']);
        self::assertSame('Penny', $events[0]['detail']['token_name']);
        self::assertSame('ai', $events[0]['actor']);

        $_GET = ['mine' => '1', 'open_only' => 'true'];
        $listed = $this->call($runner, $token['token'], 'GET', '/api/v1/handoffs', false, fn (int $u) => $api->list($u));
        self::assertSame([], $listed->payload['handoffs']);
        $_GET = ['to_agent' => 'Chief'];
        $forChief = $this->call($runner, $token['token'], 'GET', '/api/v1/handoffs', false, fn (int $u) => $api->list($u));
        self::assertSame(['From the key'], array_column($forChief->payload['handoffs'], 'title'));
    }

    public function testMorningBriefListsHandoffsForTheCallingKey(): void
    {
        $this->svc->handoffs->create($this->user, ['title' => 'Read the brief', 'to_agent' => 'Penny', 'priority' => 'high'], 'Chief');
        $this->svc->handoffs->create($this->user, ['title' => 'Not for Penny', 'to_agent' => 'Chief'], 'Penny');
        $this->svc->handoffs->create($this->user, ['title' => 'Anyone'], 'Chief');
        $penny = $this->svc->tokens->create($this->user, 'Penny', ['digest:write', 'handoffs:read']);
        $digest = $this->svc->tokens->create($this->user, 'Digest', ['digest:read', 'digest:write']);
        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        $api = new PlatformApi($runner, $this->svc);

        $with = $this->call($runner, $penny['token'], 'POST', '/api/v1/brief', true, fn (int $u) => $api->compose($u));
        self::assertSame(['Read the brief'], array_column($with->payload['open_handoffs'], 'title'));
        self::assertStringNotContainsString('Read the brief', $with->payload['brief']['body']);

        $without = $this->call($runner, $digest['token'], 'POST', '/api/v1/brief', true, fn (int $u) => $api->compose($u));
        self::assertArrayNotHasKey('open_handoffs', $without->payload);
    }

    /**
     * @param callable(int): array<string, mixed> $fn
     */
    private function call(ApiRunner $runner, string $token, string $method, string $path, bool $mutating, callable $fn): CapturedResponse
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'create_handoff';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        putenv('LIFEDASH_TEST_CAPTURE=1');
        try {
            $runner->run($fn, $method === 'POST' && str_contains($path, '/handoffs') && !str_contains($path, '/brief') ? 201 : 200, $mutating);
        } catch (CapturedResponse $response) {
            return $response;
        }
        self::fail('expected a captured response');
    }
}
