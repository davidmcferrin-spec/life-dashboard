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

    public function testJellyfinUsesTheEmbyTokenHeader(): void
    {
        $key = 'jellyfin-key-value';
        $http = new Http('http://tower.lan:8096', function (string $method, string $url, ?string $body, array $headers) use ($key): array {
            self::assertSame($key, $headers['X-Emby-Token']);
            self::assertStringNotContainsString($key, $url);
            if (str_contains($url, '/Sessions')) {
                return ['status' => 200, 'body' => '[{"UserName":"Ada","Client":"Web","DeviceName":"Desk","NowPlayingItem":{"Name":"Example"}}]'];
            }
            if (str_contains($url, '/Items/Counts')) {
                return ['status' => 200, 'body' => '{"MovieCount":4,"SeriesCount":2,"EpisodeCount":20,"SongCount":0,"AlbumCount":0,"MusicVideoCount":0,"BoxSetCount":0}'];
            }

            return ['status' => 200, 'body' => '{"Version":"10.10.0","ServerName":"Jelly","Id":"abc","OperatingSystem":"Linux"}'];
        }, ['X-Emby-Token' => $key], [$key]);
        $client = new Jellyfin($http);
        self::assertSame('10.10.0', $client->status()['version']);
        self::assertSame('Example', $client->sessions()['items'][0]['now_playing']);
        self::assertSame(4, $client->counts()['movie']);
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
