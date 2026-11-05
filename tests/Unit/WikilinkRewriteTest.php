<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Knowledge\Wikilinks;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class WikilinkRewriteTest extends TestCase
{
    public function testTitlesIgnoreFencesInlineCodeAndKeepProse(): void
    {
        $body = implode("\n", [
            'See [[Home]]',
            '```js',
            '[[Secret]]',
            '```',
            '~~~md',
            '[[Other]]',
            '~~~',
            'A `[[Hidden]]` word.',
            '````',
            '[[Quad]]',
            '````',
        ]);
        self::assertSame(['Home'], Wikilinks::titles($body));
        self::assertSame([], Wikilinks::titles("```\n[[Home]]\n"));
        self::assertSame(['After'], Wikilinks::titles("~~~\n[[Inside]]\n~~~\n[[After]]"));
    }

    public function testTitlesKeepTheFirstCasing(): void
    {
        $titles = Wikilinks::titles("See [[Weekly review]] and [[weekly review|that]] plus [[Home#top]].");
        self::assertSame(['Weekly review', 'Home'], $titles);
    }

    public function testRewriteKeepsAliasHeadingAndSkipsCode(): void
    {
        $body = implode("\n", [
            'Go to [[Home|the house]] and [[Home#Kitchen]].',
            '```',
            'example [[Home]]',
            '```',
            'Inline `[[Home]]` stays.',
            '[[Other]] stays.',
        ]);
        $next = Wikilinks::rewrite($body, 'home', 'House');
        self::assertStringContainsString('[[House|the house]]', $next);
        self::assertStringContainsString('[[House#Kitchen]]', $next);
        self::assertStringContainsString("example [[Home]]", $next);
        self::assertStringContainsString('`[[Home]]`', $next);
        self::assertStringContainsString('[[Other]]', $next);
        self::assertSame($body, Wikilinks::rewrite($body, 'Home', 'Home'));
    }

    public function testUnlinkedMentionIgnoresARealLinkAndCode(): void
    {
        self::assertFalse(Wikilinks::mentionsTitle('Only [[Pack list]] here.', 'Pack list'));
        self::assertFalse(Wikilinks::mentionsTitle("```\nPack list\n```", 'Pack list'));
        self::assertTrue(Wikilinks::mentionsTitle('Bring the Pack list tomorrow.', 'Pack list'));
        self::assertTrue(Wikilinks::mentionsTitle('See [[Home]] then Pack list.', 'Pack list'));
        self::assertFalse(Wikilinks::mentionsTitle('A packed list.', 'Pack'));
        self::assertFalse(Wikilinks::mentionsTitle('aa', 'aa'));
    }

    public function testParseAcceptsTitleHeadingAndAlias(): void
    {
        self::assertSame(
            ['title' => 'Weekly review', 'heading' => 'Top', 'alias' => 'notes'],
            Wikilinks::parse('[[Weekly review#Top|notes]]')
        );
        self::assertSame(
            ['title' => 'Home', 'heading' => null, 'alias' => null],
            Wikilinks::parse('Home')
        );
    }

    public function testParseRejectsAnEmptyLink(): void
    {
        $this->expectException(ValidationException::class);
        Wikilinks::parse('   ');
    }
}
