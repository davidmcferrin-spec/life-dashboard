<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Brief\BriefText;
use Dashboard\Graph\GraphService;
use Dashboard\Health\Authority;
use Dashboard\Health\DailyRollup;
use Dashboard\Health\Readiness;
use Dashboard\Health\WearableMap;
use PHPUnit\Framework\TestCase;

final class PlatformBundleTest extends TestCase
{
    public function testSectorIsLabeledFromThePath(): void
    {
        self::assertSame('money', ApiRunner::sectorFromPath('/api/v1/money/bills'));
        self::assertSame('health', ApiRunner::sectorFromPath('/api/v1/health/sync'));
        self::assertSame('agents', ApiRunner::sectorFromPath('/api/v1/agents/hermes/runs'));
        self::assertSame('home', ApiRunner::sectorFromPath('/api/v1/home/status'));
        self::assertNull(ApiRunner::sectorFromPath('/api/v1/digest/items'));
    }

    public function testSamsungOutranksGarminAndManualMealsWin(): void
    {
        self::assertSame('samsung', Authority::fromProvider('Samsung Health'));
        self::assertSame('health_connect', Authority::fromProvider('health_connect'));
        self::assertTrue(Authority::beats('steps', 'samsung', 'garmin'));
        self::assertFalse(Authority::beats('steps', 'manual', 'samsung'));
        self::assertTrue(Authority::beats('meal', 'manual', 'samsung'));
    }

    public function testReadinessSkipsMissingInputs(): void
    {
        $none = Readiness::score([
            'sleep_minutes' => null,
            'hrv_ms'        => 40,
            'hrv_baseline'  => null,
            'steps'         => null,
        ]);
        self::assertNull($none['score']);
        self::assertSame(0.0, $none['confidence']);

        $sleep = Readiness::score([
            'sleep_minutes' => 480,
            'hrv_ms'        => null,
            'hrv_baseline'  => null,
            'steps'         => 8000,
        ]);
        self::assertSame(100, $sleep['score']);
        self::assertSame(0.67, $sleep['confidence']);
        self::assertNotContains('hrv', $sleep['used']);
    }

    public function testRollupKeepsTheSamsungSample(): void
    {
        $days = DailyRollup::days([
            ['metric' => 'steps', 'day' => '2026-11-05', 'value' => 1000, 'authority' => 'garmin'],
            ['metric' => 'steps', 'day' => '2026-11-05', 'value' => 9000, 'authority' => 'samsung'],
            ['metric' => 'sleep', 'day' => '2026-11-05', 'value' => 420, 'authority' => 'health_connect'],
        ], []);
        self::assertSame(9000.0, $days['2026-11-05']['steps']);
        self::assertSame('samsung', $days['2026-11-05']['steps_authority']);
        self::assertSame('health_connect', $days['2026-11-05']['sleep_authority']);
        self::assertNull($days['2026-11-05']['hrv_ms']);
    }

    public function testWearableMapDoesNotInventAMissingVital(): void
    {
        $mapped = WearableMap::bundle([
            [
                'date' => '2026-11-05',
                'source' => ['provider' => 'samsung', 'device_name' => 'Galaxy Watch'],
                'duration_minutes' => 450,
                'avg_hrv_rmssd_ms' => 48,
            ],
            [
                'date' => '2026-11-04',
                'source' => ['provider' => 'garmin', 'device_name' => 'Forerunner'],
            ],
        ], [
            [
                'date' => '2026-11-05',
                'source' => ['provider' => 'health_connect'],
                'steps' => 11000,
            ],
        ], [
            [
                'id' => 'workout-1',
                'type' => 'walk',
                'name' => 'Morning walk',
                'start_time' => '2026-11-05T12:00:00Z',
                'duration_seconds' => 1800,
                'source' => ['provider' => 'samsung', 'device_name' => 'Galaxy Watch'],
            ],
        ], '2026-11-05 13:00:00');

        $metrics = array_column($mapped['points'], 'metric');
        self::assertContains('sleep', $metrics);
        self::assertContains('hrv', $metrics);
        self::assertContains('steps', $metrics);
        self::assertNotContains('resting_hr', $metrics);
        self::assertSame('samsung', $mapped['workouts'][0]['authority']);
        self::assertSame(30.0, $mapped['workouts'][0]['minutes']);
    }

    public function testBriefUsesOnlyGivenFacts(): void
    {
        $text = BriefText::render([
            'calendar' => ['connected' => false],
            'digests' => [],
            'money' => ['configured' => false],
            'home' => ['configured' => false],
            'music' => ['connected' => false],
            'health' => ['configured' => false, 'stored' => false],
        ]);
        self::assertStringContainsString('no calendar is connected', $text);
        self::assertStringContainsString('Payday: not saved yet', $text);
        self::assertStringContainsString('Open Wearables is not connected', $text);
        self::assertStringContainsString('Nothing was transferred.', $text);
        self::assertStringNotContainsString('$', $text);
        self::assertDoesNotMatchRegularExpression('/\b\d{2,}\b/', $text);

        $paid = BriefText::render([
            'calendar' => ['connected' => false],
            'digests' => [['title' => 'Cursor 1.0', 'summary' => 'A release note.']],
            'money' => [
                'configured' => true,
                'next_payday' => '2026-11-07',
                'pulls_total' => 120.5,
                'debt_total' => 40,
                'unassigned' => null,
                'bill_count' => 1,
                'bills' => [['name' => 'Rent']],
            ],
            'home' => ['configured' => false],
            'music' => ['connected' => true, 'playing' => false],
            'health' => ['configured' => true, 'stored' => false],
        ]);
        self::assertStringContainsString('$120.50', $paid);
        self::assertStringContainsString('Rent', $paid);
        self::assertStringContainsString('Cursor 1.0', $paid);
        self::assertStringNotContainsString('9999', $paid);
    }

    public function testMentionNeedsAWholeName(): void
    {
        self::assertTrue(GraphService::contains('Pay the Rent bill', 'Rent'));
        self::assertFalse(GraphService::contains('Currently rented', 'Rent'));
        self::assertFalse(GraphService::contains('A note', 'A'));
    }
}
