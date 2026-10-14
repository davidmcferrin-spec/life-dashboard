<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Media\MediaFilter;
use Dashboard\Media\MediaSources;
use Dashboard\Media\RedditFeed;
use Dashboard\Media\XFeed;
use Dashboard\Media\YouTubeFeed;
use Dashboard\Support\ValidationException;
use Dashboard\Widgets\WidgetTypeRegistry;
use PHPUnit\Framework\TestCase;

final class MediaFeedTest extends TestCase
{
    public function testSourcesAcceptChannelsSubsAndAccounts(): void
    {
        $channels = MediaSources::parse('youtube', "@MKBHD\nhttps://www.youtube.com/channel/UCabcdefghijklmnopqrstuv\n\n# skip");
        self::assertSame('handle:mkbhd', $channels[0]['key']);
        self::assertSame('UCabcdefghijklmnopqrstuv', $channels[1]['key']);
        self::assertSame('UCabcdefghijklmnopqrstuv', $channels[1]['channel_id']);

        $subs = MediaSources::parse('reddit', "r/News\nhttps://www.reddit.com/r/programming/");
        self::assertSame(['news', 'programming'], array_column($subs, 'key'));

        $accounts = MediaSources::parse('x', "@Dave\nhttps://x.com/other");
        self::assertSame(['dave', 'other'], array_column($accounts, 'key'));
        self::assertNull(MediaSources::account('https://x.com/home'));
    }

    public function testWidgetRejectsABadChannelLine(): void
    {
        $this->expectException(ValidationException::class);
        WidgetTypeRegistry::validateConfig('youtube', ['sources' => "not a channel"], static fn (): null => null);
    }

    public function testFilterIncludeExcludeAndShorts(): void
    {
        self::assertTrue(MediaFilter::allows('Apple event', 'keynote', 'https://www.youtube.com/watch?v=abcdefghijk', 'apple', 'giveaway', false));
        self::assertFalse(MediaFilter::allows('Apple giveaway', '', 'https://www.youtube.com/watch?v=abcdefghijk', 'apple', 'giveaway', false));
        self::assertFalse(MediaFilter::allows('Banana', '', 'https://www.youtube.com/watch?v=abcdefghijk', 'apple', '', false));
        self::assertFalse(MediaFilter::allows('Clip', '', 'https://www.youtube.com/shorts/abcdefghijk', '', '', true));
        self::assertTrue(MediaFilter::allows('Clip', '', 'https://www.youtube.com/shorts/abcdefghijk', '', '', false));
    }

    public function testYouTubeFeedParsesAtomAndChannelHtml(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<feed xmlns="http://www.w3.org/2005/Atom">
  <entry>
    <title>Apple event</title>
    <link rel="alternate" href="https://www.youtube.com/watch?v=abcdefghijk"/>
    <published>2026-10-01T12:00:00+00:00</published>
    <summary>A red apple</summary>
  </entry>
  <entry>
    <title>Quick #shorts</title>
    <link rel="alternate" href="https://www.youtube.com/shorts/zzzzzzzzzzz"/>
    <published>2026-10-02T12:00:00+00:00</published>
  </entry>
</feed>
XML;
        $items = YouTubeFeed::itemsFromXml($xml);
        self::assertSame('abcdefghijk', $items[0]['id']);
        self::assertSame('https://www.youtube.com/watch?v=abcdefghijk', $items[0]['url']);
        self::assertStringContainsString('apple', $items[0]['body']);
        self::assertSame('https://www.youtube.com/shorts/zzzzzzzzzzz', $items[1]['url']);
        self::assertSame('https://i.ytimg.com/vi/abcdefghijk/hqdefault.jpg', YouTubeFeed::thumb('abcdefghijk'));
        self::assertSame('UCabcdefghijklmnopqrstuv', YouTubeFeed::channelIdFromHtml('{"channelId":"UCabcdefghijklmnopqrstuv"}'));
    }

    public function testRedditAndXItems(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0"><channel>
  <item>
    <title>Apple thread</title>
    <link>https://www.reddit.com/r/news/comments/abc123/apple_thread/</link>
    <description>People said Apple</description>
    <pubDate>Thu, 01 Oct 2026 12:00:00 GMT</pubDate>
  </item>
</channel></rss>
XML;
        $items = RedditFeed::itemsFromXml($xml);
        self::assertSame('abc123', $items[0]['id']);
        self::assertSame('Apple thread', $items[0]['title']);

        $posts = XFeed::itemsFromPayload([
            'data' => [['id' => '99', 'text' => 'Apple shipped it', 'created_at' => '2026-10-01T12:00:00.000Z']],
        ], 'dave');
        self::assertSame('99', $posts[0]['id']);
        self::assertSame('https://x.com/dave/status/99', $posts[0]['url']);
        self::assertStringContainsString('Apple', $posts[0]['body']);
    }
}
