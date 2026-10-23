<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\SpotifyPlayback;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class SpotifyCatalogRefreshTest extends TestCase
{
    public function testCatalogQueryAsksForTracks(): void
    {
        self::assertSame('/tracks', SpotifyPlayback::CATALOG_PATH);
        self::assertSame(
            ['ids' => 'abc,def'],
            SpotifyPlayback::catalogQuery(['abc', 'abc', 'def', 'bad id'])
        );
        self::assertSame(['ids' => ''], SpotifyPlayback::catalogQuery([]));
    }

    public function testCatalogQueryRefusesMoreThanFifty(): void
    {
        $ids = [];
        for ($i = 0; $i < 51; $i++) {
            $ids[] = 't' . $i;
        }
        $this->expectException(ValidationException::class);
        SpotifyPlayback::catalogQuery($ids);
    }

    public function testCatalogRowsKeepSpotifyCatalogFieldsOnly(): void
    {
        $rows = SpotifyPlayback::catalogRows([
            null,
            ['type' => 'episode', 'id' => 'ep1', 'name' => 'Show'],
            [
                'id' => 'track1',
                'name' => 'Midnight',
                'artists' => [['name' => 'M83']],
                'album' => ['name' => 'Hurry Up', 'images' => [['url' => 'https://i.scdn.co/art.jpg']]],
                'duration_ms' => 243000,
                'explicit' => false,
                'popularity' => 70,
                'energy' => 0.8,
                'tempo' => 105,
                'audio_features' => ['energy' => 0.8],
            ],
            [
                'id' => 'track2',
                'name' => 'Unsafe art',
                'album' => ['images' => [['url' => 'http://example.test/art.jpg']]],
            ],
        ]);

        self::assertCount(2, $rows);
        self::assertSame('Midnight', $rows[0]['name']);
        self::assertSame(['M83'], $rows[0]['artists']);
        self::assertSame('Hurry Up', $rows[0]['album']);
        self::assertSame(243000, $rows[0]['duration_ms']);
        self::assertFalse($rows[0]['explicit']);
        self::assertSame(70, $rows[0]['popularity']);
        self::assertSame('https://i.scdn.co/art.jpg', $rows[0]['image_url']);
        self::assertArrayNotHasKey('energy', $rows[0]);
        self::assertArrayNotHasKey('tempo', $rows[0]);
        self::assertNull($rows[1]['image_url']);
    }
}
