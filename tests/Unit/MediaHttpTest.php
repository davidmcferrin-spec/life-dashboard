<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Media\MediaHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MediaHttpTest extends TestCase
{
    public function testHttpUserinfoAndWrongHostAreRejectedBeforeAnyRequest(): void
    {
        $calls = 0;
        $http  = $this->http(function () use (&$calls): array {
            $calls++;
            return ['status' => 200, 'headers' => [], 'body' => 'nope'];
        });

        foreach ([
            'http://www.youtube.com/feed',
            'https://user:secret@www.youtube.com/feed',
            'https://evil.example/feed',
        ] as $url) {
            try {
                $http->get($url, ['www.youtube.com', 'youtube.com']);
                self::fail("expected {$url} to be blocked");
            } catch (RuntimeException $e) {
                self::assertSame('Blocked URL.', $e->getMessage());
            }
        }
        self::assertSame(0, $calls);
    }

    public function testRedirectOffTheAllowlistIsNotFetched(): void
    {
        $seen = [];
        $http = $this->http(function (string $url) use (&$seen): array {
            $seen[] = $url;
            return [
                'status'  => 302,
                'headers' => ['location' => 'https://evil.example/steal'],
                'body'    => '',
            ];
        });

        try {
            $http->get('https://www.youtube.com/feeds/videos.xml', ['www.youtube.com', 'youtube.com']);
            self::fail('expected the redirect to be blocked');
        } catch (RuntimeException $e) {
            self::assertSame('Blocked URL.', $e->getMessage());
        }
        self::assertSame(['https://www.youtube.com/feeds/videos.xml'], $seen);
    }

    public function testRedirectToAnAllowlistedHostReturnsTheBody(): void
    {
        $seen = [];
        $http = $this->http(function (string $url) use (&$seen): array {
            $seen[] = $url;
            if (count($seen) === 1) {
                return [
                    'status'  => 301,
                    'headers' => ['location' => 'https://youtube.com/feeds/videos.xml?channel_id=UC1'],
                    'body'    => '',
                ];
            }
            return ['status' => 200, 'headers' => [], 'body' => '<feed>ok</feed>'];
        });

        $body = $http->get('https://www.youtube.com/feeds/videos.xml', ['www.youtube.com', 'youtube.com']);
        self::assertSame('<feed>ok</feed>', $body);
        self::assertSame([
            'https://www.youtube.com/feeds/videos.xml',
            'https://youtube.com/feeds/videos.xml?channel_id=UC1',
        ], $seen);
    }

    public function testRelativeLocationStaysOnTheSameHost(): void
    {
        $seen = [];
        $http = $this->http(function (string $url) use (&$seen): array {
            $seen[] = $url;
            if (count($seen) === 1) {
                return ['status' => 302, 'headers' => ['location' => '/xml?x=1'], 'body' => ''];
            }
            return ['status' => 200, 'headers' => [], 'body' => 'body'];
        });

        self::assertSame('body', $http->get('https://www.reddit.com/r/news.rss', ['www.reddit.com']));
        self::assertSame('https://www.reddit.com/xml?x=1', $seen[1]);
    }

    public function testHttpLocationIsBlocked(): void
    {
        $seen = [];
        $http = $this->http(function (string $url) use (&$seen): array {
            $seen[] = $url;
            return ['status' => 302, 'headers' => ['location' => 'http://www.youtube.com/feed'], 'body' => ''];
        });

        try {
            $http->get('https://www.youtube.com/feed', ['www.youtube.com']);
            self::fail('expected an http redirect to be blocked');
        } catch (RuntimeException $e) {
            self::assertSame('Blocked URL.', $e->getMessage());
        }
        self::assertCount(1, $seen);
    }

    public function testTooManyRedirectsStopsBeforeTheNextFetch(): void
    {
        $seen = [];
        $http = $this->http(function (string $url) use (&$seen): array {
            $seen[] = $url;
            $n = count($seen);
            return ['status' => 302, 'headers' => ['location' => 'https://www.youtube.com/hop' . $n], 'body' => ''];
        });

        try {
            $http->get('https://www.youtube.com/start', ['www.youtube.com']);
            self::fail('expected too many redirects');
        } catch (RuntimeException $e) {
            self::assertSame('Too many redirects.', $e->getMessage());
        }
        // The first request plus MAX_REDIRECTS hops are fetched. The next hop is not.
        self::assertCount(MediaHttp::MAX_REDIRECTS + 1, $seen);
        self::assertSame('https://www.youtube.com/hop' . MediaHttp::MAX_REDIRECTS, $seen[MediaHttp::MAX_REDIRECTS]);
        self::assertNotContains('https://www.youtube.com/hop' . (MediaHttp::MAX_REDIRECTS + 1), $seen);
    }

    /**
     * @param \Closure(string): array{status: int, headers: array<string, string>, body: string} $transport
     */
    private function http(\Closure $transport): MediaHttp
    {
        return new MediaHttp(8, $transport);
    }
}
