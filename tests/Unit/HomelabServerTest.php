<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AuditLog;
use Dashboard\Config;
use Dashboard\Homelab\HomelabException;
use Dashboard\Homelab\HomelabService;
use Dashboard\Homelab\Http;
use Dashboard\Homelab\Jellyfin;
use Dashboard\Homelab\Plex;
use Dashboard\Homelab\Settings;
use Dashboard\Homelab\Unraid;
use PHPUnit\Framework\TestCase;

final class HomelabServerTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::overlay('APP_SECRET', null);
    }

    public function testUnraidStatusUsesTheGraphqlApiAndKeepsTheKeyOutOfTheUrl(): void
    {
        $key = 'unraid-api-key-value';
        $body = null;
        $http = new Http('http://tower.lan', function (string $method, string $url, ?string $sent, array $headers) use (&$body, $key): array {
            $body = $sent;
            self::assertSame('POST', $method);
            self::assertSame('http://tower.lan/graphql', $url);
            self::assertSame($key, $headers['X-Api-Key']);
            self::assertStringNotContainsString($key, $url);

            return ['status' => 200, 'body' => json_encode([
                'data' => [
                    'online' => true,
                    'info' => [
                        'versions' => ['core' => ['unraid' => '7.2.0', 'api' => '4.0.0']],
                        'os' => ['hostname' => 'Tower', 'uptime' => '2026-11-27T12:00:00Z'],
                        'cpu' => ['brand' => 'Ryzen', 'cores' => 8, 'threads' => 16],
                    ],
                    'metrics' => [
                        'cpu' => ['percentTotal' => 12.5],
                        'memory' => ['total' => 32000000000, 'used' => 8000000000, 'available' => 24000000000, 'percentTotal' => 25],
                    ],
                    'array' => ['state' => 'STARTED'],
                ],
            ])];
        }, ['X-Api-Key' => $key], [$key]);
        $status = (new Unraid($http))->status();
        self::assertSame('7.2.0', $status['version']);
        self::assertSame('Tower', $status['hostname']);
        self::assertSame('STARTED', $status['array']);
        self::assertSame(12.5, $status['cpu_percent']);
        self::assertStringContainsString('os { hostname uptime }', (string) $body);
        self::assertIsInt($status['uptime_seconds']);
    }

    public function testDockerStopSendsTheIdAsAVariable(): void
    {
        $seen = null;
        $http = new Http('http://tower.lan', function (string $method, string $url, ?string $sent) use (&$seen): array {
            $seen = $sent;

            return ['status' => 200, 'body' => '{"data":{"docker":{"stop":{"id":"srv:abc","state":"EXITED","status":"Exited"}}}}'];
        }, ['X-Api-Key' => 'k']);
        $result = (new Unraid($http))->container('stop', 'srv:abc');
        self::assertSame('EXITED', $result['state']);
        $decoded = json_decode((string) $seen, true);
        self::assertSame('srv:abc', $decoded['variables']['id']);
        self::assertStringNotContainsString('srv:abc {', (string) $seen);
    }

    public function testArrayStopIsRefusedBeforeGraphql(): void
    {
        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $db = new HomelabMemory();
        $settings = new Settings($db);
        $settings->save(1, 'unraid', [
            'enabled' => true,
            'base_url' => 'http://tower.lan',
            'api_key' => 'unraid-api-key-value',
        ]);
        $calls = 0;
        $hub = new HomelabService($db, new AuditLog($db), $settings, function () use (&$calls): array {
            $calls++;

            return ['status' => 200, 'body' => '{"data":{}}'];
        });
        try {
            $hub->unraidArray(1, 'STOP', false, 'ai', 'unraid_array', ['token_name' => 'Ada']);
            self::fail('array stop without confirm must be refused');
        } catch (HomelabException $e) {
            self::assertSame('confirmation_required', $e->codeName);
        }
        self::assertSame(0, $calls);
        self::assertSame('unraid.array_stop', $db->audits[0]['action']);
        self::assertStringNotContainsString('unraid-api-key-value', $db->audits[0]['detail']);
        self::assertContains('unraid', Scope::HOMELAB);
        self::assertFalse(Scope::allows(['*'], 'unraid:write'));
        self::assertTrue(Scope::allows(['unraid:write'], 'unraid:read'));
    }

    public function testJellyfinUsesTheMediaBrowserHeader(): void
    {
        $key = 'jellyfin-key-value';
        $http = new Http('http://tower.lan:8096', function (string $method, string $url, ?string $body, array $headers) use ($key): array {
            self::assertSame(Jellyfin::authorization($key), $headers['Authorization'] ?? '');
            self::assertArrayNotHasKey('X-Emby-Token', $headers);
            self::assertStringNotContainsString($key, $url);
            if (str_contains($url, '/Sessions')) {
                return ['status' => 200, 'body' => '[{"UserName":"Ada","Client":"Web","DeviceName":"Desk","NowPlayingItem":{"Name":"Example"}}]'];
            }
            if (str_contains($url, '/Users')) {
                return ['status' => 200, 'body' => '[{"Name":"Ada","Id":"ada-id","Policy":{"IsAdministrator":true}}]'];
            }
            if (str_contains($url, '/Items/Counts')) {
                self::assertStringContainsString('userId=ada-id', $url);

                return ['status' => 200, 'body' => '{"MovieCount":4,"SeriesCount":2,"EpisodeCount":20,"SongCount":0,"AlbumCount":0,"MusicVideoCount":0,"BoxSetCount":0}'];
            }

            return ['status' => 200, 'body' => '{"Version":"10.11.0","ServerName":"Jelly","Id":"abc","OperatingSystem":"Linux"}'];
        }, ['Authorization' => Jellyfin::authorization($key)], [$key]);
        $client = new Jellyfin($http, '', $key);
        self::assertSame('10.11.0', $client->status()['version']);
        self::assertSame('Example', $client->sessions()['items'][0]['now_playing']);
        self::assertSame(4, $client->counts()['movie']);
    }

    public function testJellyfinLatestFollowsTheServerVersion(): void
    {
        $key = 'jellyfin-key-value';
        $users = '[{"Name":"Ada","Id":"ada-id","Policy":{"IsAdministrator":false}},{"Name":"Admin","Id":"admin-id","Policy":{"IsAdministrator":true}}]';
        $oldUrls = [];
        $old = new Http('http://tower.lan:8096', function (string $method, string $url, ?string $body, array $headers) use (&$oldUrls, $users): array {
            $oldUrls[] = $url;
            if (str_contains($url, '/System/Info')) {
                return ['status' => 200, 'body' => '{"Version":"10.8.13","ServerName":"Jelly","Id":"abc","OperatingSystem":"Linux"}'];
            }
            if (str_contains($url, '/Users') && !str_contains($url, '/Items/')) {
                return ['status' => 200, 'body' => $users];
            }
            self::assertStringContainsString('/Users/admin-id/Items/Latest', $url);

            return ['status' => 200, 'body' => '[{"Name":"Old Show","Type":"Series","ProductionYear":2018}]'];
        }, ['Authorization' => Jellyfin::authorization($key)], [$key]);
        $oldItems = (new Jellyfin($old, '', $key))->recent()['items'];
        self::assertSame('Old Show', $oldItems[0]['name']);
        self::assertSame('Series', $oldItems[0]['type']);
        self::assertSame(2018, $oldItems[0]['year']);

        $newUrls = [];
        $new = new Http('http://tower.lan:8096', function (string $method, string $url) use (&$newUrls, $users): array {
            $newUrls[] = $url;
            if (str_contains($url, '/System/Info')) {
                return ['status' => 200, 'body' => '{"Version":"10.10.7","ServerName":"Jelly","Id":"abc","OperatingSystem":"Linux"}'];
            }
            if (str_contains($url, '/Users') && !str_contains($url, '/Items/')) {
                return ['status' => 200, 'body' => $users];
            }
            if (str_contains($url, '/Search/Hints')) {
                self::assertStringContainsString('userId=ada-id', $url);
                self::assertStringContainsString('searchTerm=example', $url);

                return ['status' => 200, 'body' => '{"SearchHints":[{"Name":"Example","Type":"Movie","ProductionYear":2020}],"TotalRecordCount":1}'];
            }
            self::assertStringContainsString('/Items/Latest', $url);
            self::assertStringContainsString('userId=ada-id', $url);
            self::assertStringNotContainsString('/Users/', $url);

            return ['status' => 200, 'body' => '{"Items":[{"Name":"New Movie","Type":"Movie","ProductionYear":2024}]}'];
        }, ['Authorization' => Jellyfin::authorization($key)], [$key]);
        $client = new Jellyfin($new, 'Ada', $key);
        self::assertSame('New Movie', $client->recent()['items'][0]['name']);
        self::assertSame(2024, $client->recent()['items'][0]['year']);
        self::assertSame('Example', $client->search('example')['items'][0]['name']);
        foreach ($oldUrls as $url) {
            self::assertNotSame('/Items/Latest', parse_url($url, PHP_URL_PATH));
        }
    }

    public function testJellyfin404NamesTheBaseUrlAndDropsTheWebPage(): void
    {
        $key = 'jellyfin-key-value';
        $urls = [];
        $http = new Http('http://192.168.0.97:8096', function (string $method, string $url) use (&$urls, $key): array {
            $urls[] = (string) parse_url($url, PHP_URL_PATH);
            self::assertStringNotContainsString($key, $url);

            return ['status' => 404, 'body' => $key];
        }, ['Authorization' => Jellyfin::authorization($key)], [$key]);
        try {
            (new Jellyfin($http, '', $key))->status();
            self::fail('a missing base URL must be explained');
        } catch (HomelabException $e) {
            self::assertSame(
                'Jellyfin returned 404 for /System/Info at http://192.168.0.97:8096; if Jellyfin has a Base URL set (Dashboard > Networking), add it to the URL here, e.g. http://tower:8096/jellyfin',
                $e->getMessage()
            );
            self::assertStringNotContainsString($key, $e->getMessage());
        }
        self::assertSame(['/System/Info', '/System/Info/Public', '/jellyfin/System/Info/Public'], $urls);

        $seen = [];
        $legacy = new Http('http://tower.lan:8096', function (string $method, string $url, ?string $body, array $headers) use (&$seen, $key): array {
            $seen[] = $headers;
            if (!isset($headers['X-Emby-Token'])) {
                return ['status' => 401, 'body' => 'Unauthorized ' . $key];
            }
            self::assertSame($key, $headers['X-Emby-Token']);

            return ['status' => 200, 'body' => '{"Version":"10.8.13","ServerName":"Jelly","Id":"abc","OperatingSystem":"Linux"}'];
        }, ['Authorization' => Jellyfin::authorization($key)], [$key]);
        self::assertSame('10.8.13', (new Jellyfin($legacy, '', $key))->status()['version']);
        self::assertArrayNotHasKey('X-Emby-Token', $seen[0]);
        self::assertArrayHasKey('X-Emby-Token', $seen[1]);

        Config::overlay('APP_SECRET', 'unit-test-app-secret-value');
        $settings = new Settings(new HomelabMemory());
        $view = $settings->save(1, 'jellyfin', [
            'enabled' => true,
            'base_url' => 'http://192.168.0.97:8096/web/index.html',
            'api_key' => $key,
            'user' => 'Ada',
        ]);
        self::assertSame('http://192.168.0.97:8096', $view['base_url']);
        self::assertSame('Ada', $view['user']);
        self::assertStringNotContainsString($key, (string) json_encode($view));
        $again = $settings->save(1, 'jellyfin', [
            'enabled' => true,
            'base_url' => 'http://192.168.0.97:8096/jellyfin/web',
            'api_key' => '',
        ]);
        self::assertSame('http://192.168.0.97:8096/jellyfin', $again['base_url']);
        self::assertSame('Ada', $again['user']);
        self::assertSame($key, $settings->stored(1, 'jellyfin')['api_key']);
    }

    public function testPlexReadsSectionsAndRefreshesOne(): void
    {
        $token = 'plex-token-value';
        $urls = [];
        $http = new Http('http://tower.lan:32400', function (string $method, string $url, ?string $body, array $headers) use (&$urls, $token): array {
            $urls[] = $url;
            self::assertSame($token, $headers['X-Plex-Token']);
            self::assertStringNotContainsString($token, $url);
            if (str_ends_with((string) parse_url($url, PHP_URL_PATH), '/library/sections')) {
                return ['status' => 200, 'body' => '{"MediaContainer":{"Directory":[{"key":"1","title":"Movies","type":"movie"}]}}'];
            }
            if (str_contains($url, '/library/sections/1/all')) {
                return ['status' => 200, 'body' => '{"MediaContainer":{"totalSize":40,"size":0}}'];
            }
            if (str_contains($url, '/refresh')) {
                return ['status' => 200, 'body' => ''];
            }
            if (str_contains($url, '/identity')) {
                return ['status' => 200, 'body' => '{"MediaContainer":{"machineIdentifier":"mid","version":"1.41.0"}}'];
            }

            return ['status' => 200, 'body' => '{"MediaContainer":{"friendlyName":"Plex","version":"1.41.0"}}'];
        }, ['X-Plex-Token' => $token], [$token]);
        $client = new Plex($http);
        self::assertSame('mid', $client->status()['id']);
        $libraries = $client->libraries();
        self::assertSame(40, $libraries['items'][0]['count']);
        self::assertSame('Movies', $libraries['items'][0]['title']);
        self::assertTrue($client->refresh('1')['refreshed']);
        self::assertStringContainsString('/library/sections/1/refresh', $urls[array_key_last($urls)]);
    }
}
