<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Money\DebtComponents;
use Dashboard\Money\FoodWeek;
use Dashboard\Money\MoneyFreshness;
use Dashboard\Money\MoneyOptions;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class MoneyLedgerRulesTest extends TestCase
{
    public function testABalanceIsStaleAfterTheThresholdAndWhenItWasNeverSynced(): void
    {
        $now = new DateTimeImmutable('2026-11-22 18:00:00', new DateTimeZone('UTC'));
        $fresh = MoneyFreshness::describe('2026-11-22 17:00:00', 24, $now);
        self::assertFalse($fresh['stale']);
        self::assertSame('2026-11-22 17:00:00', $fresh['last_synced_at']);

        $old = MoneyFreshness::describe('2026-11-21 18:00:00', 24, $now);
        self::assertTrue($old['stale']);

        $exact = MoneyFreshness::describe('2026-11-21 18:00:00', 24, $now);
        self::assertTrue($exact['stale']);

        $missing = MoneyFreshness::describe(null, 24, $now);
        self::assertTrue($missing['stale']);
        self::assertNull($missing['last_synced_at']);

        $tight = MoneyFreshness::describe('2026-11-22 16:00:00', 1, $now);
        self::assertTrue($tight['stale']);
    }

    public function testComponentSumsSubtractUnappliedFundsAndFlagAMismatch(): void
    {
        self::assertNull(DebtComponents::sum(null, null, null, null, null));
        self::assertFalse(DebtComponents::mismatches(100, null));
        $sum = DebtComponents::sum(80, 10, 5, 5, 20);
        self::assertEqualsWithDelta(100.0, $sum, 0.001);
        self::assertFalse(DebtComponents::mismatches(100, $sum));
        self::assertTrue(DebtComponents::mismatches(100, DebtComponents::sum(80, 0, 0, 0, 0)));
    }

    public function testFoodWeeksRunSundayToSaturdayAndSplitTheTwoBuckets(): void
    {
        [$start, $end] = FoodWeek::weekContaining('2026-11-22');
        self::assertSame('2026-11-22', $start);
        self::assertSame('2026-11-28', $end);
        self::assertSame('0', (new DateTimeImmutable($start))->format('w'));
        self::assertSame($start, FoodWeek::weekContaining('2026-11-25')[0]);
        self::assertSame('groceries', FoodWeek::bucket('Groceries'));
        self::assertSame('eating_out', FoodWeek::bucket('Eating out'));
        self::assertNull(FoodWeek::bucket('Food'));

        $report = FoodWeek::report('2026-11-22', '2026-11-28', '2026-11-15', [
            'groceries' => 40,
            'eating_out' => 20,
        ], [
            'groceries' => 60,
            'eating_out' => 0,
        ], MoneyOptions::DEFAULT_GROCERIES, MoneyOptions::DEFAULT_EATING_OUT);
        self::assertEqualsWithDelta(60.0, $report['total']['spent'], 0.001);
        self::assertEqualsWithDelta(275.0, $report['total']['target'], 0.001);
        self::assertFalse($report['total']['over']);
        self::assertEqualsWithDelta(50.0, $report['two_week_average']['groceries'], 0.001);
        self::assertEqualsWithDelta(10.0, $report['two_week_average']['eating_out'], 0.001);
        self::assertEqualsWithDelta(60.0, $report['two_week_average']['total'], 0.001);
    }

    public function testNewMoneyEntitiesStayOnTheMoneyScope(): void
    {
        self::assertSame('payment', ApiRunner::entityFromPath('/api/v1/money/payments/4'));
        self::assertSame('inflow', ApiRunner::entityFromPath('/api/v1/money/inflows/8/received'));
        self::assertSame('benefit_pot', ApiRunner::entityFromPath('/api/v1/money/benefit-pots'));
        self::assertSame('money_options', ApiRunner::entityFromPath('/api/v1/money/options'));
        self::assertSame('account', ApiRunner::entityFromPath('/api/v1/money/accounts/3/refresh'));
        self::assertSame('transaction', ApiRunner::entityFromPath('/api/v1/money/transactions/9/category'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/money/payments/4'));
        self::assertSame(8, ApiRunner::entityIdFromPath('/api/v1/money/inflows/8/received'));
        self::assertSame('money:read', Scope::forPath('/api/v1/money/overview', false));
        self::assertSame('money:write', Scope::forPath('/api/v1/money/benefit-pots', true));
        $allow = Scope::auditEntityAllow(['money:read'], null);
        self::assertContains('payment', $allow);
        self::assertContains('inflow', $allow);
        self::assertContains('benefit_pot', $allow);
        self::assertContains('money_options', $allow);
        self::assertNotContains('sector_grants', $allow ?? []);
    }
}
