<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\Retry;
use Dashboard\Spotify\SpotifyException;
use Dashboard\Spotify\SpotifyHttp;
use PHPUnit\Framework\TestCase;

final class SpotifyRetryTest extends TestCase
{
    public function testBackoffMatchesTheSharedLimits(): void
    {
        self::assertSame(60, Retry::backoffSeconds(429, 90, 1));
        self::assertSame(0, Retry::backoffSeconds(429, 0, 1));
        self::assertSame(2, Retry::backoffSeconds(429, null, 1));
        self::assertSame(4, Retry::backoffSeconds(503, null, 2));
        self::assertSame(8, Retry::backoffSeconds(500, null, 3));
        self::assertNull(Retry::backoffSeconds(500, null, 4));
        self::assertNull(Retry::backoffSeconds(401, 5, 1));
        self::assertNull(Retry::backoffSeconds(403, null, 1));
        self::assertNull(Retry::backoffSeconds(404, null, 1));
    }

    public function testNeedsRefreshUsesASixtySecondSkew(): void
    {
        $now = strtotime('2026-10-22 12:00:00 UTC');
        self::assertTrue(Retry::needsRefresh(null, $now));
        self::assertTrue(Retry::needsRefresh('', $now));
        self::assertTrue(Retry::needsRefresh('not-a-date', $now));
        self::assertTrue(Retry::needsRefresh('2026-10-22 12:00:30', $now));
        self::assertFalse(Retry::needsRefresh('2026-10-22 12:02:00', $now));
    }

    public function testHttpRetries429ThenSucceedsWithoutSleepingInTheTest(): void
    {
        $calls = 0;
        $slept = [];
        $http = new SpotifyHttp(
            static function () use (&$calls): array {
                $calls++;
                if ($calls === 1) {
                    return ['status' => 429, 'body' => '{}', 'headers' => ['Retry-After' => '90']];
                }
                return ['status' => 200, 'body' => '{"ok":true}', 'headers' => []];
            },
            static function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            }
        );
        $body = $http->api('GET', '/me', 'access-token-example');
        self::assertSame(['ok' => true], $body);
        self::assertSame(2, $calls);
        self::assertSame([60], $slept);
    }

    public function testHttpStopsAfterFourServerErrors(): void
    {
        $calls = 0;
        $slept = [];
        $http = new SpotifyHttp(
            static function () use (&$calls): array {
                $calls++;
                return ['status' => 502, 'body' => '{"error":{"message":"upstream"}}', 'headers' => []];
            },
            static function (int $seconds) use (&$slept): void {
                $slept[] = $seconds;
            }
        );
        try {
            $http->api('GET', '/me/player', 'access-token-example');
            self::fail('A 502 should stop after four attempts.');
        } catch (SpotifyException $e) {
            self::assertStringContainsString('upstream', $e->getMessage());
            self::assertFalse($e->reauth);
        }
        self::assertSame(4, $calls);
        self::assertSame([2, 4, 8], $slept);
    }

    public function testUnauthorizedDoesNotRetryAndAsksForReconnect(): void
    {
        $calls = 0;
        $http = new SpotifyHttp(
            static function () use (&$calls): array {
                $calls++;
                return ['status' => 401, 'body' => '{"error":{"message":"The access token expired"}}', 'headers' => []];
            },
            static function (): void {
                self::fail('401 must not wait.');
            }
        );
        try {
            $http->api('GET', '/me', 'access-token-example');
            self::fail('401 should fail.');
        } catch (SpotifyException $e) {
            self::assertTrue($e->reauth);
            self::assertSame(409, $e->httpStatus);
            self::assertStringNotContainsString('access-token-example', $e->getMessage());
        }
        self::assertSame(1, $calls);
    }

    public function testRecommendations403HasAStableMessage(): void
    {
        self::assertSame(
            'Spotify does not offer recommendations or audio features to this app.',
            SpotifyHttp::message(403, 'https://api.spotify.com/v1/recommendations', '{}')
        );
        self::assertSame(
            'Spotify does not offer recommendations or audio features to this app.',
            SpotifyHttp::message(403, 'https://api.spotify.com/v1/audio-features', '{}')
        );
        self::assertSame(
            'Spotify refused that request (403).',
            SpotifyHttp::message(403, 'https://api.spotify.com/v1/me/player/play', '{}')
        );
    }

    public function testEmptyPutAndPostSendContentLength(): void
    {
        $seen = [];
        $http = new SpotifyHttp(
            static function (string $method, string $url, ?string $body, array $headers) use (&$seen): array {
                $seen[] = [$method, $body, $headers['Content-Length'] ?? null];
                return ['status' => 204, 'body' => '', 'headers' => []];
            }
        );
        $http->api('PUT', '/me/player/pause', 'token');
        $http->api('POST', '/me/player/next', 'token');
        $http->api('PUT', '/me/player/volume', 'token', null, ['volume_percent' => 40]);
        self::assertSame([
            ['PUT', '', '0'],
            ['POST', '', '0'],
            ['PUT', '', '0'],
        ], $seen);
    }

    public function testVolumeRequestKeepsTheQueryAndLength(): void
    {
        $url = '';
        $http = new SpotifyHttp(
            static function (string $method, string $seenUrl, ?string $body, array $headers) use (&$url): array {
                $url = $seenUrl;
                self::assertSame('0', $headers['Content-Length'] ?? null);
                self::assertSame('', $body);
                return ['status' => 204, 'body' => '', 'headers' => []];
            }
        );
        $http->api('PUT', '/me/player/volume', 'token', null, ['volume_percent' => 40]);
        self::assertStringContainsString('/me/player/volume', $url);
        self::assertStringContainsString('volume_percent=40', $url);
    }

    public function testRestrictionViolatedNamesTheDeviceLimit(): void
    {
        $body = '{"error":{"status":403,"message":"Player command failed: Restriction violated"}}';
        self::assertSame(
            'This device does not support volume.',
            SpotifyHttp::message(403, 'https://api.spotify.com/v1/me/player/volume', $body)
        );
        self::assertSame(
            'This device does not support that playback command.',
            SpotifyHttp::message(403, 'https://api.spotify.com/v1/me/player/shuffle', $body)
        );
    }

    public function testScrubRemovesSecrets(): void
    {
        $raw = 'Bearer abc.def access_token=secret refresh_token=other client_secret=no code_verifier=pkce';
        $clean = SpotifyHttp::scrub($raw);
        self::assertStringNotContainsString('abc.def', $clean);
        self::assertStringNotContainsString('=secret', $clean);
        self::assertStringNotContainsString('=other', $clean);
        self::assertStringContainsString('Bearer [redacted]', $clean);
        self::assertStringContainsString('access_token=[redacted]', $clean);
    }
}
