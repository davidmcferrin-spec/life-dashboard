<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Feed\FeedBoard;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class FeedBoardTest extends TestCase
{
    public function testContextUsesRealSyncState(): void
    {
        $now = new DateTimeImmutable('2026-10-23 20:00:00', new DateTimeZone('UTC'));

        $empty = FeedBoard::context(0, '2026-10-23 19:59:30', $now);
        self::assertSame(0, $empty['connected']);
        self::assertSame('Not yet', $empty['last_sync']);
        self::assertSame('Needs setup', $empty['status']);
        self::assertFalse($empty['ready']);

        $waiting = FeedBoard::context(2, null, $now);
        self::assertSame(2, $waiting['connected']);
        self::assertSame('Not yet', $waiting['last_sync']);
        self::assertSame('Waiting', $waiting['status']);
        self::assertFalse($waiting['ready']);

        $ready = FeedBoard::context(3, '2026-10-23 19:59:30', $now);
        self::assertSame('Just now', $ready['last_sync']);
        self::assertSame('Ready', $ready['status']);
        self::assertTrue($ready['ready']);
        self::assertSame('5 minutes ago', FeedBoard::context(1, '2026-10-23 19:55:00', $now)['last_sync']);
        self::assertSame('1 minute ago', FeedBoard::context(1, '2026-10-23 19:59:00', $now)['last_sync']);
        self::assertSame('3 hours ago', FeedBoard::context(1, '2026-10-23 17:00:00', $now)['last_sync']);
        self::assertSame('1 hour ago', FeedBoard::context(1, '2026-10-23 19:00:00', $now)['last_sync']);
        self::assertSame('Oct 22, 20:00 UTC', FeedBoard::context(1, '2026-10-22 20:00:00', $now)['last_sync']);
        self::assertSame('Oct 23, 21:00 UTC', FeedBoard::ago('2026-10-23 21:00:00', $now));
        self::assertSame('Not yet', FeedBoard::ago('   ', $now));
    }

    public function testArticlesKeepRealHttpLinksOnly(): void
    {
        $articles = FeedBoard::articlesFromRows([
            [
                'title' => 'Local note',
                'url' => 'https://example.test/a',
                'published_at' => '2026-10-23 18:00:00',
                'fetched_at' => '2026-10-23 19:00:00',
                'source_name' => 'Example',
                'category' => 'Technology',
            ],
            [
                'title' => 'Fetched only',
                'url' => 'http://example.test/b',
                'published_at' => '',
                'fetched_at' => '2026-10-23 12:00:00',
                'source_name' => 'Wire',
                'category' => '',
            ],
            [
                'title' => 'Script',
                'url' => 'javascript:alert(1)',
                'published_at' => '2026-10-23 18:00:00',
                'fetched_at' => '2026-10-23 18:00:00',
                'source_name' => 'Bad',
                'category' => 'Technology',
            ],
            [
                'title' => ' ',
                'url' => 'https://example.test/empty',
                'published_at' => '2026-10-23 18:00:00',
                'fetched_at' => '2026-10-23 18:00:00',
                'source_name' => 'Example',
                'category' => 'Technology',
            ],
        ]);

        self::assertCount(2, $articles);
        self::assertSame('https://example.test/a', $articles[0]['url']);
        self::assertSame('2026-10-23 18:00:00', $articles[0]['at']);
        self::assertSame('Example', $articles[0]['source']);
        self::assertSame('http://example.test/b', $articles[1]['url']);
        self::assertSame('2026-10-23 12:00:00', $articles[1]['at']);
    }

    public function testFeedsPageIsSeparateFromTheNewsGrid(): void
    {
        $index = file_get_contents(ROOT_DIR . '/public/index.php');
        $page = file_get_contents(VIEWS_DIR . '/feeds.php');
        $card = file_get_contents(VIEWS_DIR . '/partials/system-context.php');
        $css = file_get_contents(PUBLIC_DIR . '/assets/css/lifedash.css');
        self::assertIsString($index);
        self::assertIsString($page);
        self::assertIsString($card);
        self::assertIsString($css);
        self::assertStringContainsString("\$router->get('/feeds'", $index);
        self::assertStringContainsString('FeedBoard', $page);
        self::assertStringContainsString('system-context.php', $page);
        self::assertStringContainsString('id="system-context-title"', $card);
        self::assertStringContainsString('Last sync', $card);
        self::assertStringContainsString('Connected items', $card);
        self::assertStringNotContainsString('ld-mk-news', $page);
        self::assertStringNotContainsString('id="news-feed"', $page);
        self::assertStringNotContainsString('#news-feed', $css);
        self::assertStringNotContainsString('.ld-mk-news', $css);
        self::assertStringContainsString('.feeds-list', $css);
        self::assertStringContainsString('.system-context', $css);
    }
}
