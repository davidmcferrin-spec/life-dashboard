<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Pantry\PantryCatalog;
use Dashboard\Pantry\PantryDates;
use Dashboard\Pantry\PantryUnits;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class PantryDatesTest extends TestCase
{
    public function testInitialUseByKeepsZeroDaysAndLeavesANullLifeEmpty(): void
    {
        self::assertSame('2026-09-25', PantryDates::initialUseBy('2026-09-25', 0));
        self::assertSame('2026-09-28', PantryDates::initialUseBy('2026-09-25', 3));
        self::assertNull(PantryDates::initialUseBy('2026-09-25', null));
        self::assertSame(0, PantryDates::daysFor('fridge', 0, 7));
        self::assertSame(7, PantryDates::daysFor('fridge', null, 7));
    }

    public function testMoveStartsTheFreezerClockOnTheMoveDateAndKeepsAnOverride(): void
    {
        $kept = PantryDates::afterMove('freezer', '2026-09-01', '2026-09-25', '2026-12-01', true, false, 90);
        self::assertSame('2026-12-01', $kept['use_by']);
        self::assertTrue($kept['overridden']);

        $reset = PantryDates::afterMove('freezer', '2026-09-01', '2026-09-25', '2026-12-01', true, true, 90);
        self::assertSame('2026-12-24', $reset['use_by']);
        self::assertFalse($reset['overridden']);

        $fridge = PantryDates::afterMove('fridge', '2026-09-01', '2026-09-25', '2026-09-04', false, false, 3);
        self::assertSame('2026-09-04', $fridge['use_by']);

        $pantry = PantryDates::afterMove('pantry', '2026-09-01', '2026-09-25', null, false, true, 30);
        self::assertSame('2026-10-01', $pantry['use_by']);

        $unknown = PantryDates::afterMove('pantry', '2026-09-01', '2026-09-25', '2026-10-01', false, true, null);
        self::assertSame('2026-10-01', $unknown['use_by']);
        self::assertFalse($unknown['overridden']);
    }

    public function testUnitNamesFoldPluralsAndRejectUnknown(): void
    {
        self::assertSame('slice', PantryUnits::normalize('slices'));
        self::assertSame('piece', PantryUnits::normalize('Pieces'));
        self::assertSame('serving', PantryUnits::normalize(' servings '));
        self::assertSame('bottle', PantryUnits::normalize('bottles'));
        self::assertSame(PantryUnits::CANONICAL, PantryUnits::canonical());
        try {
            PantryUnits::normalize('wedge');
            self::fail('expected an unknown unit');
        } catch (ValidationException $e) {
            self::assertSame('Unknown unit.', $e->fields()['unit']);
        }
    }

    public function testCondimentLevelsStepTowardEmpty(): void
    {
        self::assertSame('half', PantryDates::nextLevel('full'));
        self::assertSame('half', PantryDates::nextLevel('most'));
        self::assertSame('low', PantryDates::nextLevel('half'));
        self::assertSame('empty', PantryDates::nextLevel('low'));
        self::assertSame('empty', PantryDates::nextLevel('nearly_empty'));
        self::assertSame('empty', PantryDates::nextLevel('empty'));
        self::assertSame(3, PantryDates::stepsLeft('full'));
        self::assertSame(3, PantryDates::stepsLeft('most'));
        self::assertSame(2, PantryDates::stepsLeft('half'));
        self::assertSame(1, PantryDates::stepsLeft('low'));
        self::assertSame(1, PantryDates::stepsLeft('nearly_empty'));
        self::assertSame(1, PantryDates::stepsLeft('empty'));
    }

    public function testOpeningTakesTheEarlierDate(): void
    {
        $tight = PantryDates::afterOpen('2026-09-28', '2026-09-25', 2, false);
        self::assertSame('2026-09-27', $tight['use_by']);
        self::assertFalse($tight['overridden']);

        $kept = PantryDates::afterOpen('2026-09-26', '2026-09-25', 7, true);
        self::assertSame('2026-09-26', $kept['use_by']);
        self::assertTrue($kept['overridden']);

        $none = PantryDates::afterOpen('2026-09-28', '2026-09-25', null, false);
        self::assertSame('2026-09-28', $none['use_by']);
    }

    public function testVaultMarkdownKeepsOnlyRowsWithAUpcAndAName(): void
    {
        $body = <<<'MD'
# Preferences

| UPC | Product | Brand | Aisle | Size |
| --- | --- | --- | --- | --- |
| 012345678905 | Example beans | BrandX | Pantry | 15 oz |
| nope | Skip me | | | |
| 123 | Too short | | | |
|  | Blank upc | | | |

Some other note.
MD;
        $rows = PantryCatalog::productsFromMarkdown($body);
        self::assertCount(1, $rows);
        self::assertSame('012345678905', $rows[0]['upc']);
        self::assertSame('Example beans', $rows[0]['name']);
        self::assertSame('BrandX', $rows[0]['brand']);
        self::assertSame('Pantry', $rows[0]['category']);
        self::assertSame('15 oz', $rows[0]['default_size']);
        self::assertNull($rows[0]['notes']);
    }

    public function testPantryScopeAndAuditEntity(): void
    {
        self::assertSame(['pantry:read'], Scope::normalize(['pantry:read']));
        self::assertContains('pantry:write', Scope::normalize(['write']));
        self::assertSame('product', ApiRunner::entityFromPath('/api/v1/pantry/products/upc/012345678905'));
        self::assertSame('pantry_lot', ApiRunner::entityFromPath('/api/v1/pantry/lots/4/open'));
        try {
            Scope::normalize(['pantry:admin']);
            self::fail('expected an unknown scope');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('scopes', $e->fields());
        }
    }
}
