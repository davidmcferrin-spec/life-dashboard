<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Support\SqlTime;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class SqlTimeTest extends TestCase
{
    private function utc(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function testUtcNowFormatsInUtcRegardlessOfInputZone(): void
    {
        $chicago = new DateTimeImmutable('2026-09-25 10:00:00', new DateTimeZone('America/Chicago'));
        self::assertSame('2026-09-25 15:00:00', SqlTime::utcNow($chicago));
    }

    public function testUtcMinutesAgo(): void
    {
        self::assertSame('2026-09-25 14:30:00', SqlTime::utcMinutesAgo(30, $this->utc('2026-09-25 15:00:00')));
    }

    public function testUtcMinutesAgoClampsNegativeToZero(): void
    {
        self::assertSame('2026-09-25 15:00:00', SqlTime::utcMinutesAgo(-10, $this->utc('2026-09-25 15:00:00')));
    }

    public function testLocalHoursFromNowUsesWallClockOfZone(): void
    {
        // 15:00 UTC = 10:00 CDT; +24 h wall clock = next day 10:00.
        self::assertSame(
            '2026-09-26 10:00:00',
            SqlTime::localHoursFromNow(24, 'America/Chicago', $this->utc('2026-09-25 15:00:00'))
        );
    }

    public function testLocalHoursFromNowAcrossDstEnd(): void
    {
        // US DST ends 2026-11-01 02:00 CDT. 06:00 UTC Oct 31 = 01:00 CDT; 24 h later is
        // still before the switch (01:00 CDT Nov 1), matching FlightWatchService's modify().
        self::assertSame(
            '2026-11-01 01:00:00',
            SqlTime::localHoursFromNow(24, 'America/Chicago', $this->utc('2026-10-31 06:00:00'))
        );
    }

    public function testNegativeHours(): void
    {
        self::assertSame(
            '2026-09-24 10:00:00',
            SqlTime::localHoursFromNow(-24, 'America/Chicago', $this->utc('2026-09-25 15:00:00'))
        );
    }
}
