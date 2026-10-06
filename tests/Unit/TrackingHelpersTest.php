<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Support\Clock;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\DatapointService;
use Dashboard\Tracking\HabitService;
use Dashboard\Tracking\MetricService;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class TrackingHelpersTest extends TestCase
{
    // ---- HabitService ------------------------------------------------

    public function testCurrentStreakStaysAliveUntilTodayEnds(): void
    {
        $done = ['2026-09-22', '2026-09-23', '2026-09-24'];
        self::assertSame(3, HabitService::currentStreak($done, '2026-09-25'), 'today not yet done');
        self::assertSame(4, HabitService::currentStreak([...$done, '2026-09-25'], '2026-09-25'));
        self::assertSame(0, HabitService::currentStreak($done, '2026-09-26'), 'missed yesterday');
        self::assertSame(0, HabitService::currentStreak([], '2026-09-25'));
    }

    public function testCurrentStreakCrossesMonthAndDst(): void
    {
        // US DST ends 2026-11-01; month boundary Oct → Nov.
        self::assertSame(4, HabitService::currentStreak(['2026-10-30', '2026-10-31', '2026-11-01', '2026-11-02'], '2026-11-02'));
    }

    public function testBestStreak(): void
    {
        self::assertSame(0, HabitService::bestStreak([]));
        self::assertSame(1, HabitService::bestStreak(['2026-01-05']));
        self::assertSame(3, HabitService::bestStreak(['2026-01-10', '2026-01-01', '2026-01-02', '2026-01-03', '2026-01-09', '2026-01-02']));
        self::assertSame(3, HabitService::bestStreak(['2024-02-28', '2024-02-29', '2024-03-01']), 'leap day');
    }

    public function testWeekStartIsMonday(): void
    {
        self::assertSame('2026-09-21', HabitService::weekStart('2026-09-21')); // Monday
        self::assertSame('2026-09-21', HabitService::weekStart('2026-09-27')); // Sunday
        self::assertSame('2026-12-28', HabitService::weekStart('2027-01-01')); // across year
    }

    // ---- Clock ---------------------------------------------------------

    public function testClockLocalDatesAndParsing(): void
    {
        $c = new Clock('America/Chicago', new DateTimeImmutable('2026-09-25 03:30:00', new DateTimeZone('UTC')));
        self::assertSame('2026-09-24', $c->today(), '22:30 CDT on the 24th');
        self::assertSame('2026-09-25 03:30:00', $c->utcString($c->now()));
        self::assertSame('2026-09-24 14:00:00', $c->utcString($c->parse('2026-09-24 09:00')), 'offsetless = local');
        self::assertSame('2026-09-24 09:00:00', $c->utcString($c->parse('2026-09-24T09:00:00Z')));
        self::assertSame('2026-09-20', Clock::shiftDate('2026-09-25', 5));
        self::assertSame('2026-09-26', Clock::shiftDate('2026-09-25', -1));

        $this->expectException(\InvalidArgumentException::class);
        Clock::normalizeDate('2026-02-30');
    }

    // ---- MetricService::validateConfig -------------------------------

    public function testMetricConfigNormalises(): void
    {
        self::assertSame(
            ['emoji' => '💧', 'color' => '#aabbcc', 'target_per_week' => 5],
            MetricService::validateConfig('habit', ['emoji' => ' 💧 ', 'color' => '#AABBCC', 'target_per_week' => '5'])
        );
        self::assertSame(
            ['min' => 1, 'max' => 5, 'labels' => ['1' => 'Awful', '5' => 'Great']],
            MetricService::validateConfig('scale', ['labels' => ['5' => 'Great', '1' => 'Awful']])
        );
        self::assertSame(['decimals' => 2, 'goal' => 10000.0, 'goal_direction' => 'up'],
            MetricService::validateConfig('number', ['decimals' => 2, 'goal' => '10000', 'goal_direction' => 'up']));
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, string}>
     */
    public static function badMetricConfigs(): iterable
    {
        yield 'unknown key'        => ['habit', ['goal' => 1], 'config'];
        yield 'bad color'          => ['habit', ['color' => 'red'], 'config.color'];
        yield 'target too high'    => ['habit', ['target_per_week' => 8], 'config.target_per_week'];
        yield 'scale inverted'     => ['scale', ['min' => 5, 'max' => 1], 'config.max'];
        yield 'scale too wide'     => ['scale', ['min' => 0, 'max' => 20], 'config.max'];
        yield 'label out of range' => ['scale', ['labels' => ['9' => 'x']], 'config.labels'];
        yield 'goal not numeric'   => ['number', ['goal' => 'lots'], 'config.goal'];
        yield 'bad direction'      => ['number', ['goal_direction' => 'sideways'], 'config.goal_direction'];
        yield 'unknown kind'       => ['vibes', [], 'kind'];
    }

    /**
     * @dataProvider badMetricConfigs
     * @param array<string, mixed> $config
     */
    public function testMetricConfigRejects(string $kind, array $config, string $field): void
    {
        try {
            MetricService::validateConfig($kind, $config);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->fields());
        }
    }

    public function testKeysAndSlugs(): void
    {
        self::assertSame('drink_water', MetricService::slugify('  Drink water!! '));
        self::assertSame('metric', MetricService::slugify('💧'));
        self::assertSame('fitbit.steps', MetricService::cleanKey('Fitbit.Steps'));
        $this->expectException(ValidationException::class);
        MetricService::cleanKey('_bad key');
    }

    // ---- DatapointService::validateValue ------------------------------

    public function testValueValidationPerKind(): void
    {
        $habit = ['kind' => 'habit', 'config' => []];
        $scale = ['kind' => 'scale', 'config' => ['min' => 1, 'max' => 5]];
        $dur   = ['kind' => 'duration', 'config' => []];
        $num   = ['kind' => 'number', 'config' => []];

        self::assertSame(1.0, DatapointService::validateValue($habit, true));
        self::assertSame(0.0, DatapointService::validateValue($habit, '0'));
        self::assertSame(3.0, DatapointService::validateValue($scale, '3'));
        self::assertSame(28800.0, DatapointService::validateValue($dur, 28800));
        self::assertSame(-12.5, DatapointService::validateValue($num, '-12.5'));

        foreach ([[$habit, 2], [$scale, 3.5], [$scale, 0], [$dur, -1], [$num, 'NaN'], [$num, ''], [$num, null], [$num, '1e20']] as [$m, $v]) {
            try {
                DatapointService::validateValue($m, $v);
                self::fail('accepted ' . var_export($v, true) . ' for ' . $m['kind']);
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
