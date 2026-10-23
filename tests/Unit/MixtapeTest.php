<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Spotify\Mixtape;
use PHPUnit\Framework\TestCase;

final class MixtapeTest extends TestCase
{
    public function testCamelotNeighborsAndRelativesAreCompatible(): void
    {
        self::assertSame('8A', Mixtape::camelot(null, 'A minor'));
        self::assertSame('8A', Mixtape::camelot(null, 'A min'));
        self::assertSame('8B', Mixtape::camelot(null, 'C major'));
        self::assertSame('8B', Mixtape::camelot('8b', null));
        self::assertTrue(Mixtape::compatible('8A', '8A'));
        self::assertTrue(Mixtape::compatible('8A', '8B'));
        self::assertTrue(Mixtape::compatible('8A', '7A'));
        self::assertTrue(Mixtape::compatible('8A', '9A'));
        self::assertTrue(Mixtape::compatible('12A', '1A'));
        self::assertTrue(Mixtape::compatible('1B', '12B'));
        self::assertFalse(Mixtape::compatible('8A', '10A'));
        self::assertFalse(Mixtape::compatible('8A', '4B'));
        self::assertFalse(Mixtape::compatible('8A', null));
    }

    public function testChristmasRunsFromThanksgivingThroughJanuarySixth(): void
    {
        $window = [
            'start_anchor' => 'thanksgiving',
            'start_month' => null,
            'start_day' => null,
            'end_month' => 1,
            'end_day' => 6,
        ];
        self::assertSame('2026-11-26', Mixtape::thanksgiving(2026)->format('Y-m-d'));
        self::assertFalse(Mixtape::inWindow($window, new \DateTimeImmutable('2026-07-04')));
        self::assertFalse(Mixtape::inWindow($window, new \DateTimeImmutable('2026-11-25')));
        self::assertTrue(Mixtape::inWindow($window, new \DateTimeImmutable('2026-11-26')));
        self::assertTrue(Mixtape::inWindow($window, new \DateTimeImmutable('2026-12-25')));
        self::assertTrue(Mixtape::inWindow($window, new \DateTimeImmutable('2027-01-06')));
        self::assertFalse(Mixtape::inWindow($window, new \DateTimeImmutable('2027-01-07')));

        $summer = [
            'start_anchor' => '',
            'start_month' => 6,
            'start_day' => 1,
            'end_month' => 8,
            'end_day' => 31,
        ];
        self::assertTrue(Mixtape::inWindow($summer, new \DateTimeImmutable('2026-07-04')));
        self::assertFalse(Mixtape::inWindow($summer, new \DateTimeImmutable('2026-12-01')));
    }

    public function testPlanFollowsTheEnergyArcAndFlagsARoughJump(): void
    {
        $tracks = [
            ['uri' => 'spotify:track:low', 'energy' => 3, 'bpm' => 90, 'camelot' => '8A'],
            ['uri' => 'spotify:track:mid', 'energy' => 6, 'bpm' => 96, 'camelot' => '8A'],
            ['uri' => 'spotify:track:peak', 'energy' => 9, 'bpm' => 102, 'camelot' => '9A'],
            ['uri' => 'spotify:track:rest', 'energy' => 5, 'bpm' => 98, 'camelot' => '8B'],
            ['uri' => 'spotify:track:end', 'energy' => 4, 'bpm' => 140, 'camelot' => '1A'],
            ['uri' => 'spotify:track:bare', 'energy' => null, 'bpm' => null, 'camelot' => null],
        ];
        $plan = Mixtape::plan($tracks, Mixtape::ARC);
        self::assertSame(
            ['spotify:track:low', 'spotify:track:mid', 'spotify:track:peak', 'spotify:track:rest', 'spotify:track:end'],
            array_column($plan['order'], 'uri')
        );
        self::assertSame(
            ['opener', 'build', 'peak', 'breather', 'closer'],
            array_column($plan['order'], 'section')
        );
        self::assertSame(['spotify:track:bare'], array_column($plan['missing'], 'uri'));
        $rough = array_values(array_filter(
            $plan['rough'],
            static fn (array $row): bool => $row['to'] === 'spotify:track:end'
        ));
        self::assertNotSame([], $rough);
        self::assertContains('bpm', $rough[0]['reasons']);
        self::assertContains('key', $rough[0]['reasons']);
    }
}
