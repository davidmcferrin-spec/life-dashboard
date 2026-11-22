<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Health\HealthLogService;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class HealthLogRulesTest extends TestCase
{
    public function testAveragesIgnoreAnEmptyRangeAndSkipMissingPulse(): void
    {
        self::assertSame(
            ['systolic' => null, 'diastolic' => null, 'pulse' => null, 'count' => 0],
            HealthLogService::averages(['systolic' => null, 'diastolic' => null, 'pulse' => null, 'n' => 0])
        );
        self::assertSame(
            ['systolic' => 125.0, 'diastolic' => 82.5, 'pulse' => 70.0, 'count' => 2],
            HealthLogService::averages(['systolic' => 125, 'diastolic' => 82.5, 'pulse' => 70, 'n' => 2])
        );
    }

    public function testItemsAcceptNamesAndObjects(): void
    {
        self::assertSame(
            [
                ['name' => 'eggs', 'quantity' => null],
                ['name' => 'toast', 'quantity' => '2'],
            ],
            HealthLogService::normalizeItems(['eggs', ['name' => 'toast', 'quantity' => 2]])
        );
        self::assertSame([], HealthLogService::normalizeItems(null));
    }

    public function testItemsRejectANonList(): void
    {
        $this->expectException(ValidationException::class);
        HealthLogService::normalizeItems(['name' => 'eggs']);
    }

    public function testFlagsAcceptStoredBooleans(): void
    {
        self::assertTrue(HealthLogService::flag('t', 'eaten_out'));
        self::assertFalse(HealthLogService::flag('f', 'active'));
        self::assertTrue(HealthLogService::flag(true, 'active'));
        self::assertFalse(HealthLogService::flag(0, 'eaten_out'));
    }
}
