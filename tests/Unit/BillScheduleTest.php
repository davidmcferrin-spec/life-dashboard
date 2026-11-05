<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Mcp\AgentPrompt;
use Dashboard\Money\BillSchedule;
use PHPUnit\Framework\TestCase;

final class BillScheduleTest extends TestCase
{
    public function testMonthlyClampsShortMonthsAndCrossesTheYear(): void
    {
        self::assertSame('2026-02-28', BillSchedule::clampDate(2026, 2, 31));
        self::assertSame('2024-02-29', BillSchedule::clampDate(2024, 2, 31));
        self::assertSame(
            ['2027-01-01'],
            BillSchedule::occurrences('2026-12-20', '2027-01-10', 'monthly', 1, null, null)
        );
    }

    public function testBiweeklyStepsFromTheAnchor(): void
    {
        self::assertSame(
            ['2026-11-13'],
            BillSchedule::occurrences('2026-11-05', '2026-11-13', 'biweekly', null, null, '2026-10-30')
        );
        self::assertSame('2026-11-13', BillSchedule::nextOnOrAfter('2026-11-05', 'biweekly', null, null, '2026-10-30'));
    }

    public function testYearlyUsesMonthAndDay(): void
    {
        self::assertSame(
            ['2027-03-01'],
            BillSchedule::occurrences('2026-11-05', '2027-12-31', 'yearly', 1, 3, null)
        );
    }

    public function testStatusAndPayday(): void
    {
        self::assertSame('past_due', BillSchedule::status('2026-11-05', '2026-11-01', false, false));
        self::assertSame('due_soon', BillSchedule::status('2026-11-05', '2026-11-08', false, false));
        self::assertSame('paid', BillSchedule::status('2026-11-05', '2026-11-01', true, false));
        self::assertSame('skipped', BillSchedule::status('2026-11-05', '2026-11-01', false, true));
        self::assertSame('2026-11-13', BillSchedule::paydayOn('2026-11-05', 'biweekly', '2026-10-16', '2026-11-13'));
        self::assertSame('2026-11-13', BillSchedule::paydayOn('2026-11-05', 'biweekly', '2026-10-30', '2026-10-30'));
        self::assertSame('2026-11-06', BillSchedule::paydayOn('2026-11-05', 'weekly', '2026-10-30', null));
        self::assertSame('2026-10-30', BillSchedule::previousPayday('2026-11-13', 'biweekly'));
        self::assertSame('2026-02-28', BillSchedule::previousPayday('2026-03-31', 'monthly'));
    }

    public function testPastWindowIsOneCycle(): void
    {
        self::assertSame('2026-10-05', BillSchedule::pastWindowStart('2026-11-05', 'monthly'));
        self::assertSame('2026-10-22', BillSchedule::pastWindowStart('2026-11-05', 'biweekly'));
    }

    public function testEnvelopeProgress(): void
    {
        self::assertSame(40.0, BillSchedule::progress(400, 1000));
        self::assertNull(BillSchedule::onTrack('2026-11-05', '2026-12-26', 400, 1000));
        self::assertFalse(BillSchedule::onTrack('2026-11-05', '2026-10-01', 100, 500));
        self::assertTrue(BillSchedule::onTrack('2026-11-05', '2026-10-01', 500, 500));
    }

    public function testMoneyPageAndAuditNames(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/money.php');
        self::assertStringContainsString('id="money-pressure"', $html);
        self::assertStringContainsString('id="money-bills"', $html);
        self::assertStringContainsString('id="money-envelopes"', $html);
        self::assertStringContainsString('id="money-snowball"', $html);
        self::assertStringContainsString('money-bills.js', $html);
        self::assertStringContainsString('Debt snowball', $html);

        $prompt = AgentPrompt::text();
        self::assertStringContainsString('propose_envelope_assignment', $prompt);
        self::assertStringContainsString('does not move money', $prompt);
        self::assertStringContainsString('Weekly food stays a weekly cap', $prompt);

        self::assertSame('bill', ApiRunner::entityFromPath('/api/v1/money/bills/4/paid'));
        self::assertSame('envelope', ApiRunner::entityFromPath('/api/v1/money/envelopes/propose'));
        self::assertSame('payday', ApiRunner::entityFromPath('/api/v1/money/payday-pressure'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/money/bills/4/paid'));
        self::assertSame(3, ApiRunner::entityIdFromPath('/api/v1/money/envelopes/3/funded'));
    }
}
