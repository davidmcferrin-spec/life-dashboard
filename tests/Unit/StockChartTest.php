<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Stock\StockChart;
use Dashboard\Support\ValidationException;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

final class StockChartTest extends TestCase
{
    public function testBlankReadKeepsTheStoredBar(): void
    {
        $stored = ['open' => 630.0, 'high' => 640.0, 'low' => 620.0, 'close' => 635.54, 'volume' => 1000.0];
        self::assertSame($stored, StockChart::keep($stored, [
            'open' => null, 'high' => null, 'low' => null, 'close' => null, 'volume' => null,
        ]));
        self::assertNull(StockChart::keep(null, [
            'open' => null, 'high' => null, 'low' => null, 'close' => null, 'volume' => null,
        ]));

        $merged = StockChart::keep($stored, [
            'open' => null, 'high' => null, 'low' => 634.0, 'close' => null, 'volume' => 4500,
        ]);
        self::assertEqualsWithDelta(635.54, $merged['close'], 0.00001);
        self::assertEqualsWithDelta(640.0, $merged['high'], 0.00001);
        self::assertEqualsWithDelta(634.0, $merged['low'], 0.00001);
        self::assertEqualsWithDelta(4500.0, $merged['volume'], 0.00001);
    }

    public function testRangesUseTheLatestSessionAndLeaveOlderBarsOutOfTheYear(): void
    {
        $bars = [
            ['date' => '2024-06-03', 'open' => 80, 'high' => 81, 'low' => 79, 'close' => 80.5, 'volume' => 10],
            ['date' => '2025-11-24', 'open' => 630, 'high' => 640, 'low' => 620, 'close' => 635.54, 'volume' => 1000],
            ['date' => '2026-11-20', 'open' => 190, 'high' => 200, 'low' => 180, 'close' => null, 'volume' => 5],
            ['date' => '2026-11-21', 'open' => 636, 'high' => 638, 'low' => 634, 'close' => 637, 'volume' => 4000],
        ];
        $ticks = [
            ['at' => '2026-11-20 21:00:00', 'price' => 634.0],
            ['at' => '2026-11-23 14:00:00', 'price' => 635.1],
            ['at' => '2026-11-23 15:00:00', 'price' => 635.54],
        ];
        $chart = StockChart::series('VOO', $bars, $ticks, new DateTimeZone('America/Chicago'));

        self::assertSame(['08:00', '09:00'], array_column($chart['ranges']['day']['points'], 'label'));
        self::assertEqualsWithDelta(635.54, $chart['ranges']['day']['points'][1]['price'], 0.00001);
        self::assertSame(['2026-11-21'], array_column($chart['ranges']['week']['points'], 'date'));
        self::assertContains('2025-11-24', array_column($chart['ranges']['year']['points'], 'date'));
        self::assertNotContains('2024-06-03', array_column($chart['ranges']['year']['points'], 'date'));
        self::assertNotContains('2026-11-20', array_column($chart['ranges']['year']['points'], 'date'));

        $year = StockChart::pick($chart, 'year');
        self::assertSame(['year'], array_keys($year['ranges']));
        try {
            StockChart::symbol('not a ticker');
            self::fail('a symbol with a space should be rejected');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('symbol', $e->fields());
        }
        try {
            StockChart::range('decade');
            self::fail('an unknown range should be rejected');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('range', $e->fields());
        }
    }
}
