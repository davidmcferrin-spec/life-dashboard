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

    public function testAValidKeyCanReadHealthAndTheActIsAudited(): void
    {
        $token = $this->svc->tokens->create($this->user, 'watch', ['health:read', 'health:write']);
        $once = $this->call((string) $token['token'], 'GET', '/api/v1/health/status', false);
        self::assertSame(200, $once->status);
        self::assertFalse($once->payload['open_wearables']['configured']);

        $again = $this->call((string) $token['token'], 'GET', '/api/v1/health/status', false);
        self::assertSame(200, $again->status);

        $events = $this->svc->audit->recent($this->user);
        self::assertNotSame([], $events);
        self::assertSame('health', $events[0]['detail']['sector'] ?? null);
        self::assertSame('health_status', $events[0]['tool']);
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

    public function testGraphLinksAWikilink(): void
    {
        $this->svc->pages->create($this->user, ['title' => 'Rent plan', 'body' => 'See [[Payday]]'], 'user', null);
        $this->svc->pages->create($this->user, ['title' => 'Payday', 'body' => 'Friday'], 'user', null);
        $visible = $this->svc->graph->query($this->user, 'Rent', 20, []);
        $kinds = array_column($visible['edges'], 'kind');
        self::assertContains('wikilink', $kinds);

        $hidden = $this->svc->graph->query($this->user, '', 20, ['bill', 'envelope']);
        foreach ($hidden['nodes'] as $node) {
            self::assertNotContains($node['kind'], ['bill', 'envelope']);
        }
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
