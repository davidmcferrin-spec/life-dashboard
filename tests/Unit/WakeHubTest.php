<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AgentPresence;
use Dashboard\Audit\AuditLog;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Router\Router;
use Dashboard\Support\ApiFault;
use Dashboard\Support\CapturedResponse;
use Dashboard\Support\ValidationException;
use Dashboard\WakeHub\WakeHubActions;
use Dashboard\WakeHub\WakeHubClient;
use Dashboard\WakeHub\WakeHubException;
use Dashboard\WakeHub\WakeHubService;
use Dashboard\WakeHub\WakeHubSettings;
use Dashboard\WakeHub\WakeHubTarget;
use PHPUnit\Framework\TestCase;

final class WakeHubTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::overlay('APP_SECRET', null);
    }

    public function testBaseUrlKeepsThePortItWasGiven(): void
    {
        self::assertSame('http://hub.lan:9000', WakeHubSettings::baseUrl('http://hub.lan:9000/'));
        self::assertSame('https://hub.lan', WakeHubSettings::baseUrl('https://hub.lan'));
        self::assertSame('http://192.168.1.20:8766', WakeHubSettings::baseUrl('http://192.168.1.20:8766'));
        foreach ([
            '' => 'base_url',
            'hub.lan:8765' => 'base_url',
            'http://user:secret@hub.lan:9000' => 'base_url',
            'http://hub.lan:9000/api' => 'base_url',
            'ftp://hub.lan' => 'base_url',
        ] as $raw => $field) {
            try {
                WakeHubSettings::baseUrl($raw);
                self::fail('expected a rejection for ' . $raw);
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->fields());
            }
        }
    }

    public function testTokenStaysEncryptedAndMasked(): void
    {
        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new WakePrefMemory();
        $settings = new WakeHubSettings($db);
        $token = 'wake-token-not-in-the-clear-99';
        $view = $settings->save(1, [
            'base_url' => 'http://hub.lan:9000',
            'token'    => $token,
        ]);
        self::assertTrue($view['configured']);
        self::assertSame('r-99', $view['token_hint']);
        self::assertSame('http://hub.lan:9000', $view['base_url']);
        self::assertStringNotContainsString($token, (string) json_encode($view));
        self::assertStringNotContainsString($token, (string) json_encode($db->rows));
        $kept = $settings->save(1, ['base_url' => 'http://hub.lan:9001', 'token' => '']);
        self::assertSame('http://hub.lan:9001', $kept['base_url']);
        self::assertSame($token, $settings->credentials(1)['token']);
        self::assertNull($settings->credentials(9));
    }

    public function testClientMapsHubErrorsWithoutTheToken(): void
    {
        $token = 'sekret-token-value';
        $calls = [];
        $client = new WakeHubClient('http://hub.lan:9000', $token,  function (string $method, string $url, ?string $body, array $headers, int $timeout) use (&$calls, $token): array {
            $calls[] = [$method, $url, $body, $headers, $timeout];
            return [
                'status' => 400,
                'body'   => '{"error":{"code":"invalid","message":"bad ' . $token . '"}}',
            ];
        });
        try {
            $client->list('/api/v1/devices');
            self::fail('expected a hub error');
        } catch (WakeHubException $e) {
            self::assertSame(400, $e->status);
            self::assertSame(400, $e->extra['hub_status']);
            self::assertStringStartsWith('bad ', $e->getMessage());
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
        self::assertSame('Bearer ' . $token, $calls[0][3]['Authorization']);
        self::assertStringNotContainsString($token, $calls[0][1]);

        $denied = new WakeHubClient('http://hub.lan:9000', $token,  static fn (): array => [
            'status' => 401,
            'body'   => '{"error":{"code":"unauthorized","message":"Bearer token required"}}',
        ]);
        try {
            $denied->list('/api/v1/devices');
            self::fail('expected a token rejection');
        } catch (WakeHubException $e) {
            self::assertSame(502, $e->status);
            self::assertSame('wakehub_unauthorized', $e->codeName);
            self::assertSame(401, $e->extra['hub_status']);
            self::assertSame('Bearer token required', $e->getMessage());
            self::assertStringNotContainsString($token, $e->getMessage());
        }

        $down = new WakeHubClient('http://hub.lan:9000', $token,  static function (): never {
            throw new \RuntimeException('HTTP request failed: GET http://hub.lan:9000/api/v1/devices');
        });
        try {
            $down->wake(\Dashboard\WakeHub\WakeHubTarget::parse(4));
            self::fail('expected an unreachable hub');
        } catch (WakeHubException $e) {
            self::assertSame('wakehub_unreachable', $e->codeName);
            self::assertSame(502, $e->status);
            self::assertStringContainsString('http://hub.lan:9000', $e->getMessage());
            self::assertStringNotContainsString($token, $e->getMessage());
        }
    }

    public function testDestructivePowerIsRefusedBeforeTheHubIsCalled(): void
    {
        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new WakePrefMemory();
        $settings = new WakeHubSettings($db);
        $settings->save(1, ['base_url' => 'http://hub.lan:9000', 'token' => 'wake-token-not-in-the-clear-99']);
        $calls = 0;
        $hub = new WakeHubService($db, new AuditLog($db), $settings, function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'body' => '{"ok":true}'];
        });
        try {
            $hub->power(1, 7, 'off', false, 'auto', 'ai', 'wake_hub_power_action', [
                'token_id'   => 4,
                'token_name' => 'Ada',
                'verb'       => 'write',
            ]);
            self::fail('off without confirm must be refused');
        } catch (WakeHubException $e) {
            self::assertSame('confirmation_required', $e->codeName);
            self::assertSame(409, $e->status);
            self::assertSame('device:7', $e->extra['device_id']);
        }
        self::assertSame(0, $calls);
        self::assertSame('wakehub.off', $db->audits[0]['action']);
        $detail = json_decode($db->audits[0]['detail'], true);
        self::assertSame('device:7', $detail['device_id']);
        self::assertSame('off', $detail['action']);
        self::assertSame('Ada', $detail['token_name']);
        self::assertFalse($detail['ok']);
        self::assertStringNotContainsString('wake-token-not-in-the-clear-99', $db->audits[0]['detail']);

        foreach (['cycle', 'reboot', 'shutdown'] as $action) {
            try {
                $hub->power(1, 7, $action, false, 'pdu', 'ai', 'wake_hub_power_action', []);
                self::fail($action . ' without confirm must be refused');
            } catch (WakeHubException $e) {
                self::assertSame('confirmation_required', $e->codeName);
            }
        }
        self::assertSame(0, $calls);
        self::assertTrue(WakeHubActions::destructive('off'));
        self::assertFalse(WakeHubActions::destructive('on'));

        try {
            $hub->devices(2);
            self::fail('another user is not configured');
        } catch (WakeHubException $e) {
            self::assertSame('wakehub_unconfigured', $e->codeName);
        }
    }

    public function testInventoryCoversEveryCollectionAndKeepsNumericDeviceIds(): void
    {
        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new WakePrefMemory();
        $settings = new WakeHubSettings($db);
        $settings->save(1, ['base_url' => 'http://hub.lan:9000', 'token' => 'wake-token-not-in-the-clear-99']);
        $calls = [];
        $hub = new WakeHubService($db, new AuditLog($db), $settings, function (string $method, string $url, ?string $body, array $headers, int $timeout) use (&$calls): array {
            $calls[] = [$method, parse_url($url, PHP_URL_PATH), $timeout];
            $path = (string) parse_url($url, PHP_URL_PATH);
            $lists = [
                '/api/v1/devices' => [['id' => 2, 'name' => 'Home Desktop', 'host' => '10.0.0.4', 'enabled' => true, 'methods' => [['kind' => 'wol']]], ['id' => 3, 'name' => 'UNRAID', 'host' => '10.0.0.8', 'enabled' => true]],
                '/api/v1/amt' => [['id' => 4, 'name' => 'Dev Workstation', 'host' => '192.168.0.243']],
                '/api/v1/plugs' => [],
                '/api/v1/jetkvm' => [],
                '/api/v1/ups' => [],
                '/api/v1/rokus' => [['id' => 6, 'name' => 'Bedroom TV', 'host' => '192.168.0.50'], ['id' => 7, 'name' => 'Office TV', 'host' => '192.168.0.51']],
                '/api/v1/pdus' => [['id' => 1, 'name' => 'PDU 192.168.0.8', 'host' => '192.168.0.8', 'outlets' => [['number' => 1, 'name' => 'Tower']]]],
            ];
            if ($path === '/api/v1/amt/4/power' && $method === 'GET') {
                return ['status' => 200, 'body' => '{"power_state":"on","name":"Dev Workstation"}'];
            }
            if ($path === '/api/v1/devices/2/status') {
                return ['status' => 200, 'body' => '{"device_id":2,"reachable":false,"elapsed_s":30}'];
            }
            if ($path === '/api/v1/devices/2/power') {
                return ['status' => 200, 'body' => '{"sent":true,"reachable":false,"elapsed_s":30.5,"name":"Home Desktop"}'];
            }
            if ($path === '/api/v1/rokus/6/power') {
                return ['status' => 200, 'body' => '{"roku_id":6,"action":"on","ok":true,"name":"Bedroom TV"}'];
            }
            if (isset($lists[$path])) {
                return ['status' => 200, 'body' => json_encode($lists[$path], JSON_THROW_ON_ERROR)];
            }

            return ['status' => 404, 'body' => '{"error":{"code":"not_found","message":"device 9 was not found"}}'];
        });
        $ids = array_column($hub->devices(1)['devices'], 'id');
        self::assertContains('device:2', $ids);
        self::assertContains('device:3', $ids);
        self::assertContains('amt:4', $ids);
        self::assertContains('pdu:1', $ids);
        self::assertContains('pdu:1:1', $ids);
        self::assertContains('roku:6', $ids);
        self::assertContains('roku:7', $ids);

        $missing = new WakeHubClient('http://hub.lan:9000', 'wake-token-not-in-the-clear-99',  static fn (): array => [
            'status' => 404,
            'body'   => '{"error":{"code":"not_found","message":"device 9 was not found"}}',
        ]);
        try {
            $missing->statusOf(WakeHubTarget::parse(9));
            self::fail('expected the hub message');
        } catch (WakeHubException $e) {
            self::assertSame(404, $e->status);
            self::assertSame('not_found', $e->codeName);
            self::assertSame('device 9 was not found', $e->getMessage());
        }

        $status = $hub->status(1, 'amt:4');
        self::assertSame('amt:4', $status['device_id']);
        self::assertSame('amt', $status['type']);
        $numeric = $hub->status(1, 2);
        self::assertSame('device:2', $numeric['device_id']);
        self::assertFalse($numeric['reachable']);
        self::assertSame(30, $numeric['elapsed_s']);

        $powered = $hub->power(1, 2, 'on', false, 'wol', 'ai', 'wake_hub_power_action', []);
        self::assertTrue($powered['sent']);
        self::assertFalse($powered['reachable']);
        self::assertSame(30.5, $powered['elapsed_s']);
        $wakeTimeout = 0;
        foreach ($calls as $call) {
            if ($call[1] === '/api/v1/devices/2/power') {
                $wakeTimeout = $call[2];
            }
        }
        self::assertGreaterThan(300, $wakeTimeout);

        $roku = $hub->power(1, 'roku:6', 'on', false, 'auto', 'ai', 'wake_hub_power_action', []);
        self::assertSame('roku:6', $roku['device_id']);
        try {
            $hub->power(1, 'roku:6', 'reboot', true, 'auto', 'ai', 'wake_hub_power_action', []);
            self::fail('a roku cannot reboot');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('action', $e->fields());
        }

        $late = new WakeHubClient('http://hub.lan:9000', 'wake-token-not-in-the-clear-99',  static fn (): array => [
            'status' => 0,
            'sent'   => true,
            'body'   => '',
        ]);
        try {
            $late->wake(WakeHubTarget::parse(2));
            self::fail('expected a timeout after the packet was sent');
        } catch (WakeHubException $e) {
            self::assertSame('wakehub_timeout', $e->codeName);
            self::assertSame(504, $e->status);
            self::assertStringContainsString('may already have been sent', $e->getMessage());
            self::assertStringNotContainsString('unreachable', $e->getMessage());
            self::assertArrayHasKey('sent', $e->extra);
            self::assertArrayHasKey('reachable', $e->extra);
            self::assertArrayHasKey('elapsed_s', $e->extra);
        }
    }

    public function testWakeSendsAnEmptyObject(): void
    {
        $body = null;
        $client = new WakeHubClient('http://hub.lan:9000', 'wake-token-not-in-the-clear-99', function (string $method, string $url, ?string $sent) use (&$body): array {
            $body = $sent;
            return ['status' => 200, 'body' => '{"sent":true,"reachable":false,"elapsed_s":1,"name":"Home Desktop"}'];
        });
        $result = $client->wake(WakeHubTarget::parse('device:4'));
        self::assertSame('{}', $body);
        self::assertTrue($result['sent']);
        self::assertFalse($result['reachable']);
        self::assertSame(1, $result['elapsed_s']);
    }

    public function testRouterAcceptsCompositeTargets(): void
    {
        $saved = [
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
        ];
        $cases = [
            ['GET', '/api/v1/wakehub/devices/amt:2/status', 'amt:2'],
            ['GET', '/api/v1/wakehub/devices/amt%3A2/status', 'amt%3A2'],
            ['POST', '/api/v1/wakehub/devices/pdu:1:3/power', 'pdu:1:3'],
            ['POST', '/api/v1/wakehub/devices/device:4/wake', 'device:4'],
            ['GET', '/api/v1/wakehub/devices/2/status', '2'],
        ];
        try {
            foreach ($cases as [$method, $uri, $id]) {
                $_SERVER['REQUEST_METHOD'] = $method;
                $_SERVER['REQUEST_URI'] = $uri;
                $seen = null;
                $router = new Router();
                $router->get('/api/v1/wakehub/devices/{id}/status', function (array $params) use (&$seen): void {
                    $seen = $params['id'];
                });
                $router->post('/api/v1/wakehub/devices/{id}/power', function (array $params) use (&$seen): void {
                    $seen = $params['id'];
                });
                $router->post('/api/v1/wakehub/devices/{id}/wake', function (array $params) use (&$seen): void {
                    $seen = $params['id'];
                });
                $router->dispatch();
                self::assertSame($id, $seen, $uri);
            }
        } finally {
            foreach ($saved as $key => $value) {
                if ($value === null) {
                    unset($_SERVER[$key]);
                } else {
                    $_SERVER[$key] = $value;
                }
            }
        }
    }

    public function testApiFailuresAreJson(): void
    {
        $saved = $_SERVER['REQUEST_URI'] ?? null;
        $_SERVER['REQUEST_URI'] = '/api/v1/wakehub/devices/amt:2/status';
        $capture = getenv('LIFEDASH_TEST_CAPTURE');
        putenv('LIFEDASH_TEST_CAPTURE=1');
        try {
            self::assertTrue(ApiFault::isApi());
            ApiFault::json(500, 'Server error.');
            self::fail('expected a JSON error');
        } catch (CapturedResponse $response) {
            self::assertSame(500, $response->status);
            self::assertSame(['error' => 'Server error.'], $response->payload);
        } finally {
            if ($saved === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $saved;
            }
            if ($capture === false) {
                putenv('LIFEDASH_TEST_CAPTURE');
            } else {
                putenv('LIFEDASH_TEST_CAPTURE=' . $capture);
            }
        }
    }

    public function testScopesAndAuditPath(): void
    {
        self::assertContains('wakehub', Scope::AREAS);
        self::assertSame('wakehub:read', Scope::forPath('/api/v1/wakehub/devices', false));
        self::assertSame('wakehub:write', Scope::forPath('/api/v1/wakehub/devices/3/power', true));
        self::assertTrue(Scope::allows(['wakehub:write'], 'wakehub:read'));
        self::assertFalse(Scope::allows(['pages:write'], 'wakehub:read'));
        self::assertSame(['wake_hub'], Scope::auditEntityAllow(['wakehub:read'], 'wake_hub'));
        self::assertSame('wake_hub', ApiRunner::entityFromPath('/api/v1/wakehub/devices/3/wake'));
        self::assertSame(3, ApiRunner::entityIdFromPath('/api/v1/wakehub/devices/3/wake'));
        $row = AgentPresence::describe([
            'id' => 1,
            'actor' => 'ai',
            'tool' => 'wake_hub_wake',
            'action' => 'wakehub.wake',
            'resource' => 'wakehub:3',
            'detail' => ['token_name' => 'Ada'],
            'created_at' => '2026-11-27 04:00:00',
        ]);
        self::assertNotNull($row);
        self::assertSame('Wake Hub', $row['area']);
    }
}

final class WakePrefMemory extends Database
{
    public function __construct()
    {
    }

    /** @var array<string, array{id: int, value: string}> */
    public array $rows = [];

    /** @var list<array{action: string, detail: string}> */
    public array $audits = [];

    private int $seq = 1;

    public function fetchOne(string $sql, array $params = []): ?array
    {
        if (str_starts_with($sql, 'SELECT pref_value')) {
            $key = $params[0] . '|' . $params[1];

            return isset($this->rows[$key]) ? ['pref_value' => $this->rows[$key]['value']] : null;
        }
        if (str_starts_with($sql, 'SELECT id')) {
            $key = $params[0] . '|' . $params[1];

            return isset($this->rows[$key]) ? ['id' => $this->rows[$key]['id']] : null;
        }

        return null;
    }

    public function execute(string $sql, array $params = []): int
    {
        if (str_starts_with($sql, 'INSERT INTO user_preferences')) {
            $this->rows[$params[0] . '|' . $params[1]] = ['id' => $this->seq++, 'value' => (string) $params[2]];

            return 1;
        }
        if (str_starts_with($sql, 'UPDATE user_preferences')) {
            $key = $params[1] . '|' . $params[2];
            if (!isset($this->rows[$key])) {
                return 0;
            }
            $this->rows[$key]['value'] = (string) $params[0];

            return 1;
        }
        if (str_starts_with($sql, 'INSERT INTO audit_events')) {
            $this->audits[] = [
                'action' => (string) $params[3],
                'detail' => (string) $params[5],
            ];

            return 1;
        }

        throw new \RuntimeException($sql);
    }
}
