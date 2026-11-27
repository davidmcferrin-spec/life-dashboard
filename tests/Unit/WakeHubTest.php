<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AgentPresence;
use Dashboard\Audit\AuditLog;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Support\ValidationException;
use Dashboard\WakeHub\WakeHubActions;
use Dashboard\WakeHub\WakeHubClient;
use Dashboard\WakeHub\WakeHubException;
use Dashboard\WakeHub\WakeHubService;
use Dashboard\WakeHub\WakeHubSettings;
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
        $client = new WakeHubClient('http://hub.lan:9000', $token, null, function (string $method, string $url, ?string $body, array $headers, int $timeout) use (&$calls, $token): array {
            $calls[] = [$method, $url, $body, $headers, $timeout];
            return [
                'status' => 400,
                'body'   => '{"error":{"code":"invalid","message":"bad ' . $token . '"}}',
            ];
        });
        try {
            $client->devices();
            self::fail('expected a hub error');
        } catch (WakeHubException $e) {
            self::assertSame(422, $e->status);
            self::assertStringNotContainsString($token, $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
        self::assertSame('Bearer ' . $token, $calls[0][3]['Authorization']);
        self::assertStringNotContainsString($token, $calls[0][1]);

        $denied = new WakeHubClient('http://hub.lan:9000', $token, null, static fn (): array => [
            'status' => 401,
            'body'   => '{"error":{"code":"unauthorized","message":"Bearer token required"}}',
        ]);
        try {
            $denied->devices();
            self::fail('expected a token rejection');
        } catch (WakeHubException $e) {
            self::assertSame(502, $e->status);
            self::assertSame('wakehub_unauthorized', $e->codeName);
            self::assertStringNotContainsString($token, $e->getMessage());
        }

        $down = new WakeHubClient('http://hub.lan:9000', $token, null, static function (): never {
            throw new \RuntimeException('HTTP request failed: GET http://hub.lan:9000/api/v1/devices');
        });
        try {
            $down->wake(4);
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
            self::assertSame(7, $e->extra['device_id']);
        }
        self::assertSame(0, $calls);
        self::assertSame('wakehub.off', $db->audits[0]['action']);
        $detail = json_decode($db->audits[0]['detail'], true);
        self::assertSame(7, $detail['device_id']);
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
