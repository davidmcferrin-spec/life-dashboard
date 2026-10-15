<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Reddit\RedditClient;
use Dashboard\Reddit\RedditListing;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

final class RedditTrackingTest extends PgsqlTestCase
{
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
    }

    public function testSaveDedupesAndFiltersAndSearch(): void
    {
        $added = $this->svc()->add($this->user, 'r/programming', 'new', 'quarkspindle', 'hiring');
        self::assertSame('programming', $added['name']);
        self::assertSame('new', $added['sort']);

        $again = $this->svc()->add($this->user, 'https://www.reddit.com/r/programming/', 'top', 'quarkspindle', '');
        self::assertSame('top', $again['sort']);
        self::assertSame('', $again['exclude']);
        self::assertCount(1, $this->svc()->list($this->user));

        $posts = RedditListing::posts((string) file_get_contents(dirname(__DIR__) . '/fixtures/reddit/hot.json'));
        $this->svc()->savePosts($this->user, 'programming', $posts);
        $posts[0]['score'] = 200;
        $this->svc()->savePosts($this->user, 'programming', $posts);

        self::assertSame(1, (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM reddit_posts WHERE user_id = ? AND external_id = ?',
            [$this->user, 'abc123']
        ));
        self::assertSame(200, (int) $this->db->fetchScalar(
            'SELECT score FROM reddit_posts WHERE user_id = ? AND external_id = ?',
            [$this->user, 'abc123']
        ));

        $matched = $this->svc()->posts($this->user, ['keyword' => 'quarkspindle']);
        self::assertSame(['abc123'], array_column($matched, 'id'));

        $this->svc()->add($this->user, 'programming', 'top', '', '');
        $high = $this->svc()->posts($this->user, ['min_score' => 150]);
        self::assertSame(['abc123'], array_column($high, 'id'));

        $recent = $this->svc()->posts($this->user, ['since' => '2025-10-01']);
        self::assertSame(['abc123'], array_column($recent, 'id'));
        $both = $this->svc()->posts($this->user, ['subreddit' => 'programming']);
        self::assertSame(['abc123', 'def456'], array_column($both, 'id'));

        $this->svc()->add($this->user, 'programming', 'top', 'quarkspindle', '');
        $widget = $this->svcWidgets()->widgets->create($this->user, 'reddit_feed', 'Reddit', ['limit' => 5]);
        $payload = $this->svcWidgets()->widgetData->forWidget($this->user, (int) $widget['id']);
        self::assertSame(['abc123'], array_column($payload['posts'], 'id'));

        $hits = $this->svcWidgets()->search->search($this->user, 'quarkspindle', ['reddit']);
        self::assertSame('reddit', $hits['hits'][0]['dataset']);
        self::assertStringContainsString('programming', (string) $hits['hits'][0]['url']);

        $comments = $this->svc()->comments($this->user, 'abc123', $this->commentClient());
        self::assertSame('lin', $comments['comments'][0]['author']);

        try {
            $this->svc()->comments($this->user, 'missing1', $this->commentClient());
            self::fail('expected an unknown post to be refused');
        } catch (NotFoundException) {
        }

        $this->svc()->remove($this->user, 'programming');
        self::assertSame([], $this->svc()->list($this->user));
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM reddit_posts WHERE user_id = ?', [$this->user]));
    }

    public function testABadNameIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->svc()->add($this->user, 'no spaces here');
    }

    private function svc(): \Dashboard\Reddit\RedditService
    {
        return $this->svcWidgets()->reddit;
    }

    private function svcWidgets(): \Dashboard\Tracking\Services
    {
        return new \Dashboard\Tracking\Services(
            $this->db,
            new \Dashboard\Support\Clock('UTC', new \DateTimeImmutable('2026-10-14 12:00:00', new \DateTimeZone('UTC')))
        );
    }

    private function commentClient(): RedditClient
    {
        $json = (string) file_get_contents(dirname(__DIR__) . '/fixtures/reddit/comments.json');
        return new RedditClient(
            static function () use ($json): array {
                return ['status' => 200, 'headers' => [], 'body' => $json];
            },
            static function (int $microseconds): void {
            },
        );
    }
}
