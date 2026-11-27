<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Api\V1\ApiV1;
use Dashboard\Auth\Auth;
use Dashboard\Router\Router;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\Clock;
use Dashboard\Tracking\Services;
use DateTimeImmutable;
use DateTimeZone;

final class HomelabRouteTest extends PgsqlTestCase
{
    private Services $svc;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)',
            ['ada', 'ada@x', 'h', 1]
        );
        $user = (int) $this->db->lastInsertId();
        $this->svc = new Services($this->db, new Clock('America/Chicago', new DateTimeImmutable('2026-11-27 16:00:00', new DateTimeZone('UTC'))));
        $scopes = [];
        foreach (['sonarr', 'radarr', 'prowlarr', 'qbittorrent'] as $area) {
            $scopes[] = $area . ':read';
            $scopes[] = $area . ':write';
        }
        $created = $this->svc->tokens->create($user, 'Homelab', $scopes, null, true);
        $this->token = (string) $created['token'];
    }

    public function testIdsWithAColonStayOnTheJsonApi(): void
    {
        $status = $this->dispatch('GET', '/api/v1/qbittorrent/torrents/abc:def');
        $this->assertJsonCode($status, 'homelab_unconfigured');

        $encoded = $this->dispatch('GET', '/api/v1/qbittorrent/torrents/abc%3Adef');
        $this->assertJsonCode($encoded, 'homelab_unconfigured');

        $pause = $this->dispatch('POST', '/api/v1/qbittorrent/torrents/abc:def/pause');
        $this->assertJsonCode($pause, 'homelab_unconfigured');

        $deleted = $this->dispatch('POST', '/api/v1/qbittorrent/torrents/abc:def/delete');
        $this->assertJsonCode($deleted, 'confirmation_required');

        $series = $this->dispatch('POST', '/api/v1/sonarr/series/12:8/delete');
        self::assertSame(422, $series->status);
        $this->assertHomelabJson($series);

        $numeric = $this->dispatch('POST', '/api/v1/sonarr/series/12/delete');
        $this->assertJsonCode($numeric, 'confirmation_required');
    }

    private function assertJsonCode(CapturedResponse $response, string $code): void
    {
        $this->assertHomelabJson($response);
        self::assertSame($code, $response->payload['code'] ?? null, json_encode($response->payload) . ' HTTP ' . $response->status);
    }

    private function assertHomelabJson(CapturedResponse $response): void
    {
        self::assertIsArray($response->payload);
        $encoded = json_encode($response->payload);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('<html', strtolower($encoded));
        self::assertStringNotContainsString('Server error', $encoded);
    }

    private function dispatch(string $method, string $uri): CapturedResponse
    {
        $saved = [
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
            'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            'HTTP_X_LIFEDASH_TOOL' => $_SERVER['HTTP_X_LIFEDASH_TOOL'] ?? null,
        ];
        $capture = getenv('LIFEDASH_TEST_CAPTURE');
        putenv('LIFEDASH_TEST_CAPTURE=1');
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $uri;
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $this->token;
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'homelab_test';
        try {
            $router = new Router();
            (new ApiV1(new Auth($this->db), $this->svc))->register($router);
            $router->dispatch();
            self::fail('The route did not return JSON for ' . $uri);
        } catch (CapturedResponse $response) {
            return $response;
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
            if ($capture === false) {
                putenv('LIFEDASH_TEST_CAPTURE');
            } else {
                putenv('LIFEDASH_TEST_CAPTURE=' . $capture);
            }
        }
    }
}
