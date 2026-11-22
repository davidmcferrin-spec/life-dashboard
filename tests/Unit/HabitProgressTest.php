<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Support\ValidationException;
use Dashboard\Tracking\HabitProgress;
use Dashboard\Tracking\MetricService;
use PHPUnit\Framework\TestCase;

final class HabitProgressTest extends TestCase
{
    public function testPartialCountIsNotDone(): void
    {
        $partial = HabitProgress::state(3, 8, false);
        self::assertSame(8.0, $partial['target']);
        self::assertSame(8.0, $partial['threshold']);
        self::assertSame(3.0, $partial['progress']);
        self::assertFalse($partial['done']);
        self::assertSame(5.0, $partial['remaining']);

        $met = HabitProgress::state(8, 8, true);
        self::assertTrue($met['done']);
        self::assertSame(0.0, $met['remaining']);
        self::assertTrue(HabitProgress::countsAsChecked(8, 8, true));
        self::assertFalse(HabitProgress::countsAsChecked(7.9, 8, true));
    }

    public function testCheckOffHabitIsDoneAtOne(): void
    {
        self::assertFalse(HabitProgress::countsAsChecked(0, 1, false));
        self::assertTrue(HabitProgress::countsAsChecked(1, 1, false));
        $row = HabitProgress::state(1, 1, true);
        self::assertTrue($row['done']);
        self::assertSame(0.0, $row['remaining']);
    }

    public function testDownGoalNeedsAReading(): void
    {
        $empty = HabitProgress::downState(0, 180, false);
        self::assertFalse($empty['done']);
        $over = HabitProgress::downState(190, 180, true);
        self::assertFalse($over['done']);
        self::assertSame(10.0, $over['remaining']);
        $met = HabitProgress::downState(170, 180, true);
        self::assertTrue($met['done']);
        self::assertSame(0.0, $met['remaining']);
    }

    public function testMealMatchingSkipsPrepAndEatAtHomeWhenOut(): void
    {
        self::assertTrue(HabitProgress::matchesMeal('Breakfast', 'breakfast', 'breakfast', true));
        self::assertTrue(HabitProgress::matchesMeal('Eat at home', 'eat_at_home', 'lunch', false));
        self::assertFalse(HabitProgress::matchesMeal('Eat at home', 'eat_at_home', 'lunch', true));
        self::assertTrue(HabitProgress::matchesMeal('Restaurants', 'eat_out', 'dinner', true));
        self::assertFalse(HabitProgress::matchesMeal('Restaurants', 'eat_out', 'dinner', false));
        self::assertFalse(HabitProgress::matchesMeal('Meal prep', 'meal_prep', 'dinner', false));
        self::assertTrue(HabitProgress::matchesMeal('Meals', 'meals', 'snack', false));
        self::assertFalse(HabitProgress::matchesMeal('Read', 'read', 'breakfast', false));
    }

    public function testDailyTargetConfig(): void
    {
        self::assertSame(
            ['daily_target' => 8.0],
            MetricService::validateConfig('habit', ['daily_target' => '8'])
        );
        try {
            MetricService::validateConfig('habit', ['daily_target' => 0]);
            self::fail('accepted a zero daily target');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('config.daily_target', $e->fields());
        }
    }

    public function testHealthLogRoutesUseHealthScopes(): void
    {
        self::assertSame('health:read', Scope::forPath('/api/v1/health/blood-pressure', false));
        self::assertSame('health:write', Scope::forPath('/api/v1/health/meal-log', true));
        self::assertSame('health:write', Scope::forPath('/api/v1/health/medications/4/doses', true));
        self::assertSame('metrics:read', Scope::forPath('/api/v1/habits/today', false));
        self::assertSame('metrics:write', Scope::forPath('/api/v1/datapoints/increment', true));
        self::assertSame('medication_dose', ApiRunner::entityFromPath('/api/v1/health/medications/4/doses'));
        self::assertSame('meal_log', ApiRunner::entityFromPath('/api/v1/health/meal-log'));
        self::assertSame('blood_pressure', ApiRunner::entityFromPath('/api/v1/health/blood-pressure'));
        self::assertSame('appointment', ApiRunner::entityFromPath('/api/v1/health/appointments/9/update'));
        self::assertSame('health_point', ApiRunner::entityFromPath('/api/v1/health/meals'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/health/medications/4/update'));
        self::assertContains('blood_pressure', Scope::HEALTH_ENTITIES);
        self::assertContains('meal_log', Scope::AUDIT_ENTITIES);
    }
}
