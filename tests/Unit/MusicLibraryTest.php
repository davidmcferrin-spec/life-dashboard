<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\MusicLibrary;
use PHPUnit\Framework\TestCase;

final class MusicLibraryTest extends TestCase
{
    public function testWaveformIsEmptyWithoutStoredSamples(): void
    {
        self::assertSame([], MusicLibrary::waveform(null));
        self::assertSame([], MusicLibrary::waveform([]));
        // A single energy number is not a loudness series.
        self::assertSame([], MusicLibrary::waveform(7));
    }

    public function testWaveformScalesStoredSamples(): void
    {
        self::assertSame([0, 100], MusicLibrary::waveform([-20, -5]));
        self::assertSame([40, 40], MusicLibrary::waveform([-8, -8]));
    }

    public function testLoudnessUsesOnlySegmentPeaks(): void
    {
        $samples = MusicLibrary::loudnessFromAnalysis([
            'segments' => [
                ['loudness_max' => -12.5],
                ['start' => 1.2],
                ['loudness_max' => -3],
            ],
        ]);
        self::assertSame([-12.5, -3.0], $samples);
        self::assertSame([], MusicLibrary::loudnessFromAnalysis(['segments' => []]));
        self::assertSame([], MusicLibrary::loudnessFromAnalysis([]));
    }

    public function testPickSamplesKeepsRealPoints(): void
    {
        $source = range(0, 99);
        $picked = MusicLibrary::pickSamples($source, 48);
        self::assertCount(48, $picked);
        foreach ($picked as $value) {
            self::assertContains($value, $source);
        }
        self::assertSame($source, MusicLibrary::pickSamples($source, 100));
    }

    public function testBuildReportsDoesNotInventGenresOrLoudness(): void
    {
        $reports = MusicLibrary::buildReports(['abc', 'gone'], [
            'tracks' => [[
                'id' => 'abc',
                'artists' => [['id' => 'ar1']],
            ]],
            'artists' => [[
                'id' => 'ar1',
                'genres' => ['Indie Pop', 'not a genre!!!'],
            ]],
            'analysis' => [
                'abc' => ['analysis' => 'unavailable', 'body' => []],
            ],
        ]);
        self::assertFalse($reports[0]['missing']);
        self::assertSame(['indie pop'], $reports[0]['genres']);
        self::assertArrayNotHasKey('loudness', $reports[0]);
        self::assertSame('unavailable', $reports[0]['analysis']);
        self::assertTrue($reports[1]['missing']);
        self::assertSame('indie pop', MusicLibrary::genreName('Indie Pop', false));
        self::assertSame('r&b', MusicLibrary::genreName('r&b', false));
        self::assertNull(MusicLibrary::genreName('not a genre!!!', false));
    }

    public function testReadyAnalysisKeepsReturnedPeaksOnly(): void
    {
        $reports = MusicLibrary::buildReports(['abc'], [
            'tracks' => [[
                'id' => 'abc',
                'artists' => [],
            ]],
            'artists' => [],
            'analysis' => [
                'abc' => [
                    'analysis' => 'ready',
                    'body' => [
                        'segments' => [
                            ['loudness_max' => -12.5],
                            ['start' => 1],
                            ['loudness_max' => -3],
                        ],
                    ],
                ],
            ],
        ]);
        self::assertSame([-12.5, -3.0], $reports[0]['loudness']);
        self::assertSame([], $reports[0]['genres']);
    }
}
