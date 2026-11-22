<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\TasteProfile;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class TasteProfileTest extends TestCase
{
    public function testDislikesNormalizeTracksArtistsAndTitles(): void
    {
        $lists = TasteProfile::dislikes([
            'tracks' => ['spotify:track:abc', 'abc', 'xyz'],
            'artists' => ['Newsong', 'newsong'],
            'titles' => ['Christmas Shoes', '  christmas   shoes '],
        ]);
        self::assertSame(['spotify:track:abc', 'spotify:track:xyz'], $lists['tracks']);
        self::assertSame(['Newsong'], $lists['artists']);
        self::assertSame(['Christmas Shoes'], $lists['titles']);
    }

    public function testATitleBlocksTheSongEvenWithAnArticle(): void
    {
        $dislikes = ['titles' => ['Christmas Shoes']];
        self::assertTrue(TasteProfile::blocked($dislikes, [
            'uri' => 'spotify:track:shoes',
            'name' => 'The Christmas Shoes',
            'artists' => ['Newsong'],
        ]));
        self::assertFalse(TasteProfile::blocked($dislikes, [
            'uri' => 'spotify:track:other',
            'name' => 'Blue Christmas',
            'artists' => ['Elvis Presley'],
        ]));
        self::assertFalse(TasteProfile::blocked(['titles' => ['IT']], [
            'uri' => 'spotify:track:other',
            'name' => 'Wait',
        ]));
        self::assertTrue(TasteProfile::blocked(['artists' => ['Elvis Presley']], [
            'uri' => 'spotify:track:other',
            'name' => 'Blue Christmas',
            'artists' => [['name' => 'Elvis Presley']],
        ]));
    }

    public function testMergeAddsUntilReplace(): void
    {
        $merged = TasteProfile::merge(
            ['titles' => ['Christmas Shoes']],
            ['artists' => ['Mannheim Steamroller']],
            false
        );
        self::assertSame(['Christmas Shoes'], $merged['titles']);
        self::assertSame(['Mannheim Steamroller'], $merged['artists']);
        $replaced = TasteProfile::merge($merged, ['titles' => ['Silent Night']], true);
        self::assertSame(['Silent Night'], $replaced['titles']);
        self::assertSame([], $replaced['artists']);
    }

    public function testABadTrackUriIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        TasteProfile::dislikes(['tracks' => ['not a uri']]);
    }
}
