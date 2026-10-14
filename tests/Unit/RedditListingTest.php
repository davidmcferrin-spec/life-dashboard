<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Reddit\RedditClient;
use Dashboard\Reddit\RedditListing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RedditListingTest extends TestCase
{
    public function testPostsFromASavedListing(): void
    {
        $posts = RedditListing::posts((string) file_get_contents(dirname(__DIR__) . '/fixtures/reddit/hot.json'));

        self::assertCount(2, $posts);
        self::assertSame('abc123', $posts[0]['id']);
        self::assertSame('programming', $posts[0]['subreddit']);
        self::assertSame('ada', $posts[0]['author']);
        self::assertSame(128, $posts[0]['score']);
        self::assertSame(14, $posts[0]['num_comments']);
        self::assertSame('https://example.com/postgres-php', $posts[0]['url']);
        self::assertSame('https://www.reddit.com/r/programming/comments/abc123/postgres_and_php/', $posts[0]['permalink']);
        self::assertSame(gmdate('Y-m-d H:i:s', 1760000000), $posts[0]['created_utc']);
        self::assertSame('Article', $posts[0]['flair']);
        self::assertStringContainsString('quarkspindle', $posts[0]['selftext']);
        self::assertSame('def456', $posts[1]['id']);
        self::assertNull($posts[1]['flair']);
    }

    public function testCommentsSkipDeletedAndMore(): void
    {
        $comments = RedditListing::comments((string) file_get_contents(dirname(__DIR__) . '/fixtures/reddit/comments.json'));

        self::assertCount(1, $comments);
        self::assertSame('lin', $comments[0]['author']);
        self::assertSame(40, $comments[0]['score']);
        self::assertStringContainsString('index', $comments[0]['body']);
    }

    public function testPublicListingSendsAUserAgentAndStopsAfterARateLimit(): void
    {
        $calls = [];
        $client = $this->client(function (string $method, string $url, array $headers) use (&$calls): array {
            $calls[] = [$method, $url, $headers];
            return ['status' => 429, 'headers' => ['x-ratelimit-remaining' => '0'], 'body' => ''];
        });

        try {
            $client->listing('programming', 'new');
            self::fail('expected a rate limit');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('rate limit', $e->getMessage());
        }
        self::assertTrue($client->rateLimited());
        self::assertSame('web:life-dashboard:1.0 (test)', $calls[0][2]['User-Agent']);
        self::assertStringContainsString('/r/programming/new.json', $calls[0][1]);

        try {
            $client->listing('programming', 'new');
            self::fail('expected the second call to stop before the network');
        } catch (RuntimeException) {
        }
        self::assertCount(1, $calls);
    }

    public function testRedirectOffRedditIsNotFetched(): void
    {
        $calls = [];
        $client = $this->client(function (string $method, string $url) use (&$calls): array {
            $calls[] = $url;
            return ['status' => 302, 'headers' => ['location' => 'https://evil.example/steal'], 'body' => ''];
        });

        try {
            $client->listing('news', 'hot');
            self::fail('expected the redirect to be blocked');
        } catch (RuntimeException $e) {
            self::assertSame('Blocked URL.', $e->getMessage());
        }
        self::assertSame(['https://www.reddit.com/r/news/hot.json?limit=25&raw_json=1'], $calls);
    }

    public function testOauthUsesTheAppToken(): void
    {
        $fixture = (string) file_get_contents(dirname(__DIR__) . '/fixtures/reddit/hot.json');
        $calls = [];
        $client = new RedditClient(
            function (string $method, string $url, array $headers, ?string $body) use (&$calls, $fixture): array {
                $calls[] = [$method, $url, $headers, $body];
                if (str_contains($url, 'access_token')) {
                    return ['status' => 200, 'headers' => [], 'body' => '{"access_token":"tok","expires_in":3600}'];
                }
                return ['status' => 200, 'headers' => ['x-ratelimit-remaining' => '10'], 'body' => $fixture];
            },
            static function (int $microseconds): void {
            },
            'web:life-dashboard:1.0 (test)',
            'client-id',
            'secret',
        );

        $posts = $client->listing('programming', 'top');
        self::assertSame('abc123', $posts[0]['id']);
        self::assertSame('POST', $calls[0][0]);
        self::assertSame('Basic ' . base64_encode('client-id:secret'), $calls[0][2]['Authorization']);
        self::assertSame('GET', $calls[1][0]);
        self::assertStringStartsWith('https://oauth.reddit.com/r/programming/top.json', $calls[1][1]);
        self::assertStringContainsString('t=week', $calls[1][1]);
        self::assertSame('Bearer tok', $calls[1][2]['Authorization']);
        self::assertSame('web:life-dashboard:1.0 (test)', $calls[1][2]['User-Agent']);
    }

    public function testRedditScopeIsItsOwnArea(): void
    {
        self::assertSame('reddit:read', Scope::forPath('/api/v1/reddit/posts', false));
        self::assertSame('reddit:write', Scope::forPath('/api/v1/reddit/subs', true));
        self::assertContains('reddit:read', Scope::normalize(['read']));
        self::assertTrue(Scope::allows(['reddit:read'], 'reddit:read'));
        self::assertFalse(Scope::allows(['reddit:read'], 'pages:read'));
    }

    /**
     * @param \Closure(string, string, array<string, string>, ?string): array{status: int, headers: array<string, string>, body: string} $transport
     */
    private function client(\Closure $transport): RedditClient
    {
        return new RedditClient(
            $transport,
            static function (int $microseconds): void {
            },
            'web:life-dashboard:1.0 (test)',
        );
    }
}
