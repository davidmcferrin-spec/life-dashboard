<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Support\ValidationException;
use Dashboard\Tracking\HabitService;
use PHPUnit\Framework\TestCase;

final class HabitAdminTest extends TestCase
{
    public function testFrequencyWordsAndNumbers(): void
    {
        self::assertSame(7, HabitService::targetPerWeek('daily'));
        self::assertSame(7, HabitService::targetPerWeek('Every day'));
        self::assertSame(5, HabitService::targetPerWeek('weekdays'));
        self::assertSame(1, HabitService::targetPerWeek('weekly'));
        self::assertSame(3, HabitService::targetPerWeek(3));
        self::assertSame(3, HabitService::targetPerWeek('3'));
        self::assertSame('daily', HabitService::frequencyLabel(7));
        self::assertSame('weekdays', HabitService::frequencyLabel(5));
        self::assertSame('weekly', HabitService::frequencyLabel(1));
        self::assertSame('4', HabitService::frequencyLabel(4));
        self::assertNull(HabitService::frequencyLabel(null));
    }

    public function testFrequencyRejectsUnknownValues(): void
    {
        foreach (['monthly', 0, 8, ''] as $bad) {
            try {
                HabitService::targetPerWeek($bad);
                self::fail('accepted ' . var_export($bad, true));
            } catch (ValidationException $e) {
                self::assertArrayHasKey('frequency', $e->fields());
            }
        }
    }
}
