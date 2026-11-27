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

final class WakeHubRouteTest extends PgsqlTestCase
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
        $created = $this->svc->tokens->create($user, 'Wake', ['wakehub:read', 'wakehub:write'], null, true);
        $this->token = (string) $created['token'];
    }

    public function testCompositeIdsReturnJsonInsteadOfTheHtmlErrorPage(): void
    {
        $status = $this->dispatch('GET', '/api/v1/wakehub/devices/amt:2/status');
        $this->assertJsonWakeError($status);

        $encoded = $this->dispatch('GET', '/api/v1/wakehub/devices/amt%3A2/status');
        $this->assertJsonWakeError($encoded);

        $outlet = $this->dispatch('GET', '/api/v1/wakehub/devices/pdu:1:3/status');
        $this->assertJsonWakeError($outlet);

        $device = $this->dispatch('GET', '/api/v1/wakehub/devices/device:4/status');
        $this->assertJsonWakeError($device);

        $numeric = $this->dispatch('GET', '/api/v1/wakehub/devices/2/status');
        $this->assertJsonWakeError($numeric);

        $wake = $this->dispatch('POST', '/api/v1/wakehub/devices/device:4/wake');
        $this->assertJsonWakeError($wake);

        $power = $this->dispatch('POST', '/api/v1/wakehub/devices/amt:2/power');
        self::assertIsArray($power->payload);
        self::assertArrayNotHasKey(0, $power->payload);
        $encodedPayload = json_encode($power->payload);
        self::assertIsString($encodedPayload);
        self::assertStringNotContainsString('<html', strtolower($encodedPayload));
        self::assertStringNotContainsString('Server error', $encodedPayload);
    }

    private function assertJsonWakeError(CapturedResponse $response): void
    {
        self::assertIsArray($response->payload);
        self::assertSame(
            'wakehub_unconfigured',
            $response->payload['code'] ?? null,
            json_encode($response->payload) . ' HTTP ' . $response->status
        );
        self::assertStringContainsString('not configured', (string) ($response->payload['error'] ?? ''));
        $encoded = json_encode($response->payload);
        self::assertIsString($encoded);
        self::assertStringNotContainsString('<html', strtolower($encoded));
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
        $_SERVER['HTTP_X_LIFEDASH_TOOL'] = 'wake_hub_device_status';
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
