<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Auth\Auth;
use Dashboard\Health\HealthService;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Tracking\Services;

final class PlatformBundleTest extends PgsqlTestCase
{
    private int $user;

    private Services $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->svc = new Services(
            $this->db,
            new Clock('America/Chicago', new \DateTimeImmutable('2026-11-05 15:00:00', new \DateTimeZone('UTC')))
        );
    }

    protected function tearDown(): void
    {
        putenv('LIFEDASH_TEST_CAPTURE');
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_LIFEDASH_TOOL'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        parent::tearDown();
    }

    public function testHealthReadsFailClosedUntilGrantedAndOnceIsSpent(): void
    {
        $token = $this->svc->tokens->create($this->user, 'watch', ['health:read', 'health:write']);
        $denied = $this->call((string) $token['token'], 'GET', '/api/v1/health/status', false);
        self::assertSame(403, $denied->status);
        self::assertStringStartsWith('needs-grant:', (string) ($denied->payload['error'] ?? ''));

        $this->svc->grants->save($this->user, 'token', (int) $token['id'], 'health', 'once');
        $once = $this->call((string) $token['token'], 'GET', '/api/v1/health/status', false);
        self::assertSame(200, $once->status);
        self::assertFalse($once->payload['open_wearables']['configured']);

        $spent = $this->call((string) $token['token'], 'GET', '/api/v1/health/status', false);
        self::assertSame(403, $spent->status);
        self::assertSame('deny', $this->svc->grants->mode($this->user, 'token', (int) $token['id'], 'health'));

        $events = $this->svc->grants->recentAudits($this->user);
        self::assertNotSame([], $events);
        self::assertSame('health', $events[0]['sector']);
    }

    public function testAlwaysAuditsWithoutReturningToDeny(): void
    {
        $token = $this->svc->tokens->create($this->user, 'watch', ['health:read', 'health:write']);
        $this->svc->grants->save($this->user, 'token', (int) $token['id'], 'health', 'always');
        self::assertSame(200, $this->call((string) $token['token'], 'GET', '/api/v1/health/readiness', false)->status);
        self::assertSame(200, $this->call((string) $token['token'], 'GET', '/api/v1/health/readiness', false)->status);
        self::assertSame('always', $this->svc->grants->mode($this->user, 'token', (int) $token['id'], 'health'));
        $audit = $this->db->fetchOne(
            "SELECT detail::text AS detail FROM audit_events WHERE user_id = ? AND action = 'GET' ORDER BY id DESC LIMIT 1",
            [$this->user]
        );
        self::assertIsArray($audit);
        self::assertStringContainsString('always', (string) $audit['detail']);
        self::assertStringContainsString('health', (string) $audit['detail']);
    }

    public function testManualHealthLogDoesNotInventReadiness(): void
    {
        $health = new HealthService($this->db, $this->svc->clock);
        $health->logBody($this->user, ['weight_lb' => 180, 'day' => '2026-11-05']);
        $ready = $health->readiness($this->user, '2026-11-05');
        self::assertNull($ready['row']);
        $brief = $health->brief($this->user);
        self::assertIsArray($brief['brief']);
        self::assertStringContainsString('no vitals are stored', (string) $brief['brief']['body']);
        self::assertStringContainsString('180', (string) $brief['brief']['body']);
        self::assertStringNotContainsString('Readiness 0', (string) $brief['brief']['body']);
    }

    public function testGraphLinksAWikilinkAndHidesBillsWithoutAGrant(): void
    {
        $this->svc->pages->create($this->user, ['title' => 'Rent plan', 'body' => 'See [[Payday]]'], 'user', null);
        $this->svc->pages->create($this->user, ['title' => 'Payday', 'body' => 'Friday'], 'user', null);
        $visible = $this->svc->graph->query($this->user, 'Rent', 20, []);
        $kinds = array_column($visible['edges'], 'kind');
        self::assertContains('wikilink', $kinds);

        $token = $this->svc->tokens->create($this->user, 'pages', ['pages:read']);
        $hidden = $this->svc->graph->query($this->user, '', 20, ['bill', 'envelope']);
        foreach ($hidden['nodes'] as $node) {
            self::assertNotContains($node['kind'], ['bill', 'envelope']);
        }
        self::assertSame('deny', $this->svc->grants->mode($this->user, 'token', (int) $token['id'], 'money'));
    }

    private function call(string $token, string $method, string $path, bool $mutating): CapturedResponse
    {
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'health_status';
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = $method;
        putenv('LIFEDASH_TEST_CAPTURE=1');
        $runner = new ApiRunner(new Auth($this->db), $this->svc->tokens, $this->svc->audit);
        try {
            $runner->run(function (int $userId) use ($path): array {
                if (str_contains($path, 'readiness')) {
                    return $this->svc->health->readiness($userId, null);
                }

                return $this->svc->health->status($userId);
            }, 200, $mutating);
        } catch (CapturedResponse $response) {
            return $response;
        }
        self::fail('expected a captured response');
    }
}
