<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AgentPresence;
use Dashboard\Audit\AuditLog;
use Dashboard\Auth\OAuthRules;
use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Homelab\Catalog;
use Dashboard\Homelab\HomelabException;
use Dashboard\Homelab\HomelabService;
use Dashboard\Homelab\Http;
use Dashboard\Homelab\Prowlarr;
use Dashboard\Homelab\Qbit;
use Dashboard\Homelab\Servarr;
use Dashboard\Homelab\Settings;
use Dashboard\Router\Router;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class HomelabTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::overlay('APP_SECRET', null);
    }

    public function testBaseUrlKeepsAPathAndRejectsSecretsInTheUrl(): void
    {
        self::assertSame('http://tower.lan:8989', Settings::baseUrl('http://tower.lan:8989/', false));
        self::assertSame('http://tower.lan:8989/sonarr', Settings::baseUrl('http://tower.lan:8989/sonarr/', true));
        self::assertSame('', Settings::baseUrl('  ', false));
        foreach ([
            'tower.lan:8989',
            'http://user:secret@tower.lan:8989',
            'http://tower.lan:8989/sonarr?apikey=1',
            'http://tower.lan:8989/a:b',
            'ftp://tower.lan',
        ] as $raw) {
            try {
                Settings::baseUrl($raw, true);
                self::fail('expected a rejection for ' . $raw);
            } catch (ValidationException $e) {
                self::assertArrayHasKey('base_url', $e->fields());
            }
        }
    }

    public function testSecretsStayEncryptedAndMasked(): void
    {
        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new HomelabMemory();
        $settings = new Settings($db);
        $key = 'sonarr-api-key-not-in-the-clear';
        $view = $settings->save(1, 'sonarr', [
            'enabled' => true,
            'base_url' => 'http://tower.lan:8989',
            'api_key' => $key,
        ]);
        self::assertTrue($view['ready']);
        self::assertSame('lear', $view['secret_hint']);
        self::assertStringNotContainsString($key, (string) json_encode($view));
        self::assertStringNotContainsString($key, (string) json_encode($db->rows));
        $kept = $settings->save(1, 'sonarr', ['base_url' => 'http://tower.lan:8989/sonarr', 'api_key' => '']);
        self::assertSame('http://tower.lan:8989/sonarr', $kept['base_url']);
        self::assertSame($key, $settings->stored(1, 'sonarr')['api_key']);
        $settings->save(1, 'qbittorrent', [
            'enabled' => false,
            'base_url' => 'http://tower.lan:8080',
            'username' => 'david',
            'password' => 'qbit-password-value',
        ]);
        $qbit = $settings->viewOne(1, 'qbittorrent');
        self::assertFalse($qbit['ready']);
        self::assertTrue($qbit['configured']);
        self::assertSame('password', $qbit['auth']);
        self::assertSame('david', $qbit['username']);
        self::assertStringNotContainsString('qbit-password-value', (string) json_encode($qbit));
        self::assertSame($key, $settings->stored(1, 'sonarr')['api_key']);
    }

    public function testServarrReadsARecordedStatusAndHidesTheKey(): void
    {
        $key = 'sekret-sonarr-key';
        $calls = [];
        $http = new Http('http://tower.lan:8989/sonarr', function (string $method, string $url, ?string $body, array $headers) use (&$calls, $key): array {
            $calls[] = [$method, $url, $body, $headers];
            self::assertSame($key, $headers['X-Api-Key']);
            self::assertStringNotContainsString($key, $url);

            return ['status' => 200, 'body' => '{"version":"4.0.15.2941","appName":"Sonarr","instanceName":"TV","osName":"Linux"}'];
        }, ['X-Api-Key' => $key, 'Accept' => 'application/json'], [$key]);
        $status = (new Servarr('sonarr', $http))->status();
        self::assertSame('4.0.15.2941', $status['version']);
        self::assertSame('TV', $status['instance']);
        self::assertSame('http://tower.lan:8989/sonarr/api/v3/system/status', $calls[0][1]);

        $leaky = new Http('http://tower.lan:8989', function () use ($key): array {
            return ['status' => 400, 'body' => '{"message":"bad ' . $key . '"}'];
        }, ['X-Api-Key' => $key], [$key]);
        try {
            (new Servarr('sonarr', $leaky))->status();
            self::fail('expected an upstream error');
        } catch (HomelabException $e) {
            self::assertSame('homelab_error', $e->codeName);
            self::assertSame(400, $e->extra['upstream_status']);
            self::assertStringNotContainsString($key, $e->getMessage());
            self::assertStringContainsString('[redacted]', $e->getMessage());
            self::assertStringContainsString('HTTP 400', $e->getMessage());
            self::assertStringContainsString('/api/v3/system/status', $e->getMessage());
        }
    }

    public function testQueueAcceptsThePagedShape(): void
    {
        $http = new Http('http://tower.lan:7878', function (): array {
            return ['status' => 200, 'body' => '{"totalRecords":3,"records":[{"title":"Example","status":"downloading","size":10,"sizeleft":4,"protocol":"torrent","indexer":"Test"}]}'];
        }, ['X-Api-Key' => 'k']);
        $queue = (new Servarr('radarr', $http))->queue();
        self::assertSame(3, $queue['total']);
        self::assertSame('Example', $queue['items'][0]['title']);
        self::assertSame(4, $queue['items'][0]['left']);
    }

    public function testProwlarrSearchAndAppsUseTheV1Paths(): void
    {
        $seen = [];
        $http = new Http('http://tower.lan:9696', function (string $method, string $url) use (&$seen): array {
            $seen[] = $url;
            if (str_contains($url, '/search')) {
                return ['status' => 200, 'body' => '[{"title":"Example","indexer":"RARBG","size":5,"seeders":2,"leechers":1,"protocol":"torrent"}]'];
            }

            return ['status' => 200, 'body' => '[{"id":1,"name":"Sonarr","implementation":"Sonarr","syncLevel":"fullSync"}]'];
        }, ['X-Api-Key' => 'k']);
        $client = new Prowlarr($http);
        $hits = $client->search('example show');
        self::assertSame('RARBG', $hits['items'][0]['indexer']);
        $apps = $client->apps();
        self::assertSame('fullSync', $apps['items'][0]['sync_level']);
        self::assertStringContainsString('/api/v1/search?query=example+show', $seen[0]);
        self::assertStringContainsString('/api/v1/applications', $seen[1]);
    }

    public function testQbitLogsInThenFallsBackFromStopToPause(): void
    {
        $password = 'qbit-password-value';
        $calls = [];
        $http = new Http('http://tower.lan:8080', function (string $method, string $url, ?string $body, array $headers) use (&$calls, $password): array {
            $calls[] = [$method, $url, $body, $headers];
            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/auth/login')) {
                self::assertStringContainsString('password=' . rawurlencode($password), (string) $body);
                self::assertStringNotContainsString($password, $url);

                return ['status' => 200, 'body' => 'Ok.', 'headers' => ['set-cookie' => 'SID=session-cookie; Path=/']];
            }
            if (str_contains($url, '/torrents/stop')) {
                return ['status' => 404, 'body' => 'Not Found'];
            }
            self::assertSame('SID=session-cookie', $headers['Cookie'] ?? '');

            return ['status' => 200, 'body' => ''];
        }, [], [$password]);
        $result = (new Qbit($http, 'password', 'david', $password))->pause('abc:def');
        self::assertSame('http://tower.lan:8080', $calls[0][3]['Origin'] ?? '');
        self::assertSame('http://tower.lan:8080/', $calls[0][3]['Referer'] ?? '');
        self::assertSame('pause', $result['action']);
        self::assertSame('abc:def', $result['hashes']);
        self::assertStringEndsWith('/api/v2/torrents/stop', (string) parse_url($calls[1][1], PHP_URL_PATH));
        self::assertStringEndsWith('/api/v2/torrents/pause', (string) parse_url($calls[2][1], PHP_URL_PATH));
        self::assertStringContainsString('hashes=abc%3Adef', (string) $calls[2][2]);
    }

    public function testQbitAuthModesSkipLoginAndNameTheFailure(): void
    {
        $noneCalls = [];
        $none = new Http('http://tower.lan:8080/', function (string $method, string $url, ?string $body, array $headers) use (&$noneCalls): array {
            $noneCalls[] = $url;
            self::assertArrayNotHasKey('Cookie', $headers);
            self::assertArrayNotHasKey('Authorization', $headers);
            self::assertSame('http://tower.lan:8080', $headers['Origin'] ?? '');

            return ['status' => 403, 'body' => 'Forbidden'];
        }, [], []);
        try {
            (new Qbit($none, 'none'))->status();
            self::fail('a 403 must be reported');
        } catch (HomelabException $e) {
            self::assertSame(
                'qBittorrent (auth: none) returned 403 Forbidden for /api/v2/app/version: login required or this server\'s IP is not in the bypass whitelist',
                $e->getMessage()
            );
        }
        self::assertCount(1, $noneCalls);
        self::assertStringNotContainsString('/auth/login', $noneCalls[0]);

        $token = 'qbt_exampletokenvalue12345678';
        $tokenCalls = [];
        $tokenHttp = new Http('http://tower.lan:8080', function (string $method, string $url, ?string $body, array $headers) use (&$tokenCalls, $token): array {
            $tokenCalls[] = $url;
            self::assertSame('Bearer ' . $token, $headers['Authorization'] ?? '');
            self::assertArrayNotHasKey('Cookie', $headers);
            if (str_contains($url, '/app/version')) {
                return ['status' => 200, 'body' => 'v5.2.0'];
            }

            return ['status' => 200, 'body' => '{"dl_info_speed":1,"up_info_speed":2,"connection_status":"connected","dht_nodes":3}'];
        }, [], [$token]);
        $status = (new Qbit($tokenHttp, 'token', '', '', $token))->status();
        self::assertSame('v5.2.0', $status['version']);
        foreach ($tokenCalls as $url) {
            self::assertStringNotContainsString('/auth/login', $url);
        }

        $portCookie = [];
        $password = 'qbit-password-value';
        $cookieHttp = new Http('http://tower.lan:8080', function (string $method, string $url, ?string $body, array $headers) use (&$portCookie, $password): array {
            $portCookie[] = [$url, $headers];
            if (str_contains($url, '/auth/login')) {
                return ['status' => 200, 'body' => 'Ok.', 'headers' => ['set-cookie' => 'QBT_SID_8080=port-cookie; Path=/']];
            }
            self::assertSame('QBT_SID_8080=port-cookie', $headers['Cookie'] ?? '');
            if (str_contains($url, '/app/version')) {
                return ['status' => 200, 'body' => 'v4.6.0'];
            }

            return ['status' => 200, 'body' => '{"dl_info_speed":0,"up_info_speed":0,"connection_status":"connected","dht_nodes":0}'];
        }, [], [$password]);
        try {
            (new Qbit($cookieHttp, 'password', 'david', $password))->status();
        } catch (HomelabException) {
            self::fail('a port-qualified session cookie must be reused');
        }
        self::assertStringContainsString('/auth/login', $portCookie[0][0]);

        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new HomelabMemory();
        $settings = new Settings($db);
        $settings->save(1, 'qbittorrent', [
            'enabled' => true,
            'base_url' => 'http://tower.lan:8080',
            'username' => 'david',
            'password' => $password,
        ]);
        self::assertSame('password', $settings->stored(1, 'qbittorrent')['auth']);
        $noneView = $settings->save(1, 'qbittorrent', [
            'enabled' => true,
            'base_url' => 'http://tower.lan:8080',
            'auth' => 'none',
            'password' => '',
            'token' => '',
        ]);
        self::assertSame('none', $noneView['auth']);
        self::assertTrue($noneView['ready']);
        self::assertSame($password, $settings->stored(1, 'qbittorrent')['password']);
        $tokenView = $settings->save(1, 'qbittorrent', [
            'enabled' => true,
            'base_url' => 'http://tower.lan:8080',
            'auth' => 'token',
            'token' => $token,
        ]);
        self::assertSame('token', $tokenView['auth']);
        self::assertSame('5678', $tokenView['token_hint']);
        self::assertStringNotContainsString($token, (string) json_encode($tokenView));
        self::assertStringNotContainsString($password, (string) json_encode($tokenView));
    }

    public function testQbitLoginTreats204AndASessionCookieAsSuccess(): void
    {
        $password = 'qbit-password-value';
        $calls = [];
        $http = new Http('http://tower.lan:8080', function (string $method, string $url, ?string $body, array $headers) use (&$calls, $password): array {
            $calls[] = $url;
            if (str_contains($url, '/auth/login')) {
                self::assertStringContainsString('username=admin', (string) $body);
                self::assertStringNotContainsString($password, $url);

                return ['status' => 204, 'body' => '', 'headers' => ['set-cookie' => 'QBT_SID_8080=port-cookie; Path=/']];
            }
            self::assertSame('QBT_SID_8080=port-cookie', $headers['Cookie'] ?? '');
            if (str_contains($url, '/app/version')) {
                return ['status' => 200, 'body' => 'v5.1.0'];
            }

            return ['status' => 200, 'body' => '{"dl_info_speed":0,"up_info_speed":0,"connection_status":"connected","dht_nodes":0}'];
        }, [], [$password]);
        $status = (new Qbit($http, 'password', 'admin', $password))->status();
        self::assertSame('v5.1.0', $status['version']);
        self::assertStringContainsString('/auth/login', $calls[0]);

        $fails = new Http('http://tower.lan:8080', function (): array {
            return ['status' => 200, 'body' => 'Fails.', 'headers' => ['set-cookie' => 'SID=ignored']];
        }, [], [$password]);
        try {
            (new Qbit($fails, 'password', 'admin', $password))->status();
            self::fail('Fails. is a rejected login');
        } catch (HomelabException $e) {
            self::assertStringContainsString('auth: password', $e->getMessage());
            self::assertStringContainsString('200', $e->getMessage());
            self::assertStringContainsString('/api/v2/auth/login', $e->getMessage());
            self::assertStringContainsString('Fails.', $e->getMessage());
            self::assertStringNotContainsString($password, $e->getMessage());
        }
    }

    public function testDeleteIsRefusedBeforeTheAppIsCalled(): void
    {
        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new HomelabMemory();
        $settings = new Settings($db);
        $settings->save(1, 'sonarr', [
            'enabled' => true,
            'base_url' => 'http://tower.lan:8989',
            'api_key' => 'sonarr-api-key-not-in-the-clear',
        ]);
        $calls = 0;
        $hub = new HomelabService($db, new AuditLog($db), $settings, function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => '{}'];
        });
        try {
            $hub->remove(1, 'sonarr', 12, false, true, 'ai', 'sonarr_delete', [
                'token_id' => 4,
                'token_name' => 'Ada',
            ]);
            self::fail('delete without confirm must be refused');
        } catch (HomelabException $e) {
            self::assertSame('confirmation_required', $e->codeName);
            self::assertSame(409, $e->status);
            self::assertStringContainsString('files', $e->getMessage());
        }
        self::assertSame(0, $calls);
        self::assertSame('sonarr.delete', $db->audits[0]['action']);
        $detail = json_decode($db->audits[0]['detail'], true);
        self::assertFalse($detail['ok']);
        self::assertSame('12', $db->audits[0]['resource_tail']);
        self::assertStringNotContainsString('sonarr-api-key-not-in-the-clear', $db->audits[0]['detail']);

        try {
            $hub->torrentDelete(1, 'abc:def', false, true, 'ai', 'qbittorrent_delete', []);
            self::fail('torrent file delete without confirm must be refused');
        } catch (HomelabException $e) {
            self::assertSame('confirmation_required', $e->codeName);
        }
        self::assertSame(0, $calls);
    }

    public function testPathSegmentsWithAColonAreEncodedAndRouted(): void
    {
        self::assertSame('/api/v2/torrents/abc%3Adef', Http::encodePath('/api/v2/torrents/abc:def'));
        $saved = [
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
        ];
        $cases = [
            ['POST', '/api/v1/qbittorrent/torrents/abc:def/delete', 'abc:def'],
            ['POST', '/api/v1/qbittorrent/torrents/abc%3Adef/pause', 'abc%3Adef'],
            ['GET', '/api/v1/sonarr/series/12/search', '12'],
        ];
        try {
            foreach ($cases as [$method, $uri, $id]) {
                $_SERVER['REQUEST_METHOD'] = $method;
                $_SERVER['REQUEST_URI'] = $uri;
                $seen = null;
                $router = new Router();
                $router->post('/api/v1/qbittorrent/torrents/{hash}/delete', function (array $params) use (&$seen): void {
                    $seen = $params['hash'];
                });
                $router->post('/api/v1/qbittorrent/torrents/{hash}/pause', function (array $params) use (&$seen): void {
                    $seen = $params['hash'];
                });
                $router->get('/api/v1/sonarr/series/{id}/search', function (array $params) use (&$seen): void {
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

    public function testScopesAreExplicitPerService(): void
    {
        self::assertSame(Catalog::ids(), Scope::HOMELAB);
        self::assertSame('sonarr:read', Scope::forPath('/api/v1/sonarr/series', false));
        self::assertSame('sonarr:write', Scope::forPath('/api/v1/sonarr/series/12/delete', true));
        self::assertSame('qbittorrent:write', Scope::forPath('/api/v1/qbittorrent/torrents/abc:def/delete', true));
        self::assertSame('prowlarr:read', Scope::forPath('/api/v1/prowlarr/search', false));
        self::assertSame(['sonarr:read', 'qbittorrent:write'], Scope::normalize(['sonarr:read', 'qbittorrent:write']));
        self::assertFalse(Scope::allows(['*'], 'sonarr:read'));
        self::assertFalse(Scope::allows(['*'], 'qbittorrent:write'));
        self::assertFalse(Scope::allows(array_map(static fn (string $area): string => $area . ':read', Scope::CORE), 'radarr:read'));
        self::assertTrue(Scope::allows(['sonarr:write'], 'sonarr:read'));
        self::assertFalse(Scope::allows(['sonarr:read'], 'sonarr:write'));
        self::assertFalse(Scope::allows(['radarr:write'], 'sonarr:read'));
        self::assertSame(['sonarr'], Scope::auditEntityAllow(['sonarr:read'], 'sonarr'));
        self::assertSame('qbittorrent', ApiRunner::entityFromPath('/api/v1/qbittorrent/torrents/abc:def/delete'));
        self::assertNotContains('sonarr:read', Scope::normalize(['read']));
        self::assertNotContains('sonarr:read', Scope::normalize(['*']));
        self::assertContains('sonarr:read', OAuthRules::known(false));
        self::assertFalse(OAuthRules::withinAllowed('sonarr:read', ['*']));
        self::assertTrue(OAuthRules::withinAllowed('sonarr:read', ['*', 'sonarr:read']));
        $row = AgentPresence::describe([
            'id' => 1,
            'actor' => 'ai',
            'tool' => 'sonarr_delete',
            'action' => 'sonarr.delete',
            'resource' => 'sonarr:12',
            'detail' => ['token_name' => 'Ada'],
            'created_at' => '2026-11-27 04:00:00',
        ]);
        self::assertNotNull($row);
        self::assertSame('Sonarr', $row['area']);
    }
}

final class HomelabMemory extends Database
{
    public function __construct()
    {
    }

    /** @var array<string, array{id: int, value: string}> */
    public array $rows = [];

    /** @var list<array{action: string, detail: string, resource_tail: string}> */
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
            $resource = (string) $params[4];
            $this->audits[] = [
                'action' => (string) $params[3],
                'detail' => (string) $params[5],
                'resource_tail' => (string) (str_contains($resource, ':') ? substr($resource, (int) strpos($resource, ':') + 1) : $resource),
            ];

            return 1;
        }

        throw new \RuntimeException($sql);
    }
}
