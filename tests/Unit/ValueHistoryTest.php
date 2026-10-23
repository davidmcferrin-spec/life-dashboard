<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Invest\ValueHistory;
use PHPUnit\Framework\TestCase;

final class ValueHistoryTest extends TestCase
{
    public function testPortfolioValueSumsKnownPricesAndStaysNullWhenAnyPriceIsMissing(): void
    {
        self::assertSame(0.0, ValueHistory::portfolioValue([]));
        self::assertNull(ValueHistory::portfolioValue([
            ['market_value' => 100.0],
            ['market_value' => null],
        ]));
        self::assertSame(300.0, ValueHistory::portfolioValue([
            ['market_value' => 100.0],
            ['market_value' => 200.0],
        ]));
    }

    public function testPositionReturnUsesCostBasisAndRefusesAZeroOrUnknownCost(): void
    {
        self::assertSame(300.0, ValueHistory::positionValue(150.0, 2.0, true));
        self::assertNull(ValueHistory::positionValue(150.0, 2.0, false));
        self::assertSame(100.0, ValueHistory::unrealized(150.0, 100.0, 2.0, true, true));
        self::assertEqualsWithDelta(0.5, ValueHistory::positionReturn(150.0, 100.0, 2.0, true, true), 0.0000001);
        self::assertNull(ValueHistory::positionReturn(150.0, 0.0, 2.0, true, true));
        self::assertNull(ValueHistory::positionReturn(150.0, 100.0, 2.0, false, true));
        self::assertNull(ValueHistory::positionReturn(150.0, null, 2.0, true, false));
    }

    public function testDayChangeBuyingPowerAndAnnualReturnUseStoredRowsOnly(): void
    {
        $rows = [
            ['as_of' => '2025-10-23', 'market_value' => 200.0, 'cash' => 9.0],
            ['as_of' => '2026-10-22', 'market_value' => 250.0, 'cash' => 40.0],
            ['as_of' => '2026-10-23', 'market_value' => 300.0, 'cash' => null],
        ];
        $metrics = ValueHistory::metrics($rows, '2026-10-23');

        self::assertEqualsWithDelta(50.0, $metrics['day_change'], 0.0000001);
        self::assertEqualsWithDelta(0.2, $metrics['day_change_ratio'], 0.0000001);
        self::assertNull($metrics['buying_power']);
        self::assertEqualsWithDelta(0.5, $metrics['annual_return'], 0.0000001);
        self::assertSame(['2026-10-22', '2026-10-23'], array_column($metrics['series'], 'date'));
    }

    public function testAZeroPriorKeepsTheAmountAndDropsTheRatio(): void
    {
        $metrics = ValueHistory::metrics([
            ['as_of' => '2026-10-22', 'market_value' => 0.0],
            ['as_of' => '2026-10-23', 'market_value' => 10.0],
        ], '2026-10-23');

        self::assertSame(10.0, $metrics['day_change']);
        self::assertNull($metrics['day_change_ratio']);
    }

    public function testAMissingComparisonOrZeroBaseIsNull(): void
    {
        $onlyToday = ValueHistory::metrics([
            ['as_of' => '2026-10-23', 'market_value' => 300.0, 'cash' => 25.0],
        ], '2026-10-23');
        self::assertNull($onlyToday['day_change']);
        self::assertNull($onlyToday['day_change_ratio']);
        self::assertNull($onlyToday['annual_return']);
        self::assertSame(25.0, $onlyToday['buying_power']);

        $zeroBase = ValueHistory::metrics([
            ['as_of' => '2025-10-23', 'market_value' => 0.0],
            ['as_of' => '2026-10-23', 'market_value' => 10.0],
        ], '2026-10-23');
        self::assertNull($zeroBase['annual_return']);
    }

    public function testSeriesKeepsTheSixMonthWindowAndSkipsUnknownTotals(): void
    {
        $asOf = '2026-10-23';
        $start = ValueHistory::shift($asOf, '-6 months');
        self::assertSame('2026-04-23', $start);
        $dayBefore = ValueHistory::shift((string) $start, '-1 day');

        $metrics = ValueHistory::metrics([
            ['as_of' => '2025-10-23', 'market_value' => 200.0],
            ['as_of' => (string) $dayBefore, 'market_value' => 1.0],
            ['as_of' => (string) $start, 'market_value' => 2.0],
            ['as_of' => '2026-07-01', 'market_value' => null],
            ['as_of' => $asOf, 'market_value' => 300.0],
            ['as_of' => '2026-10-24', 'market_value' => 999.0],
        ], $asOf);

        self::assertSame(['2026-04-23', '2026-10-23'], array_column($metrics['series'], 'date'));
        self::assertSame([2.0, 300.0], array_column($metrics['series'], 'market_value'));
    }

    public function testShiftFollowsCalendarOverflowAndRejectsABadDate(): void
    {
        self::assertSame('2023-03-01', ValueHistory::shift('2024-02-29', '-1 year'));
        self::assertNull(ValueHistory::shift('2026-02-31', '-1 day'));
        self::assertNull(ValueHistory::shift('nope', '-1 day'));
    }
}
