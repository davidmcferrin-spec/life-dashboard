<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Shopping\ShoppingText;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class ShoppingTextTest extends TestCase
{
    public function testParseQuantityUnitAndName(): void
    {
        $row = ShoppingText::parse('2 lb chicken');
        self::assertSame('chicken', $row['name']);
        self::assertSame(2.0, $row['quantity']);
        self::assertSame('lb', $row['unit']);
        self::assertSame('Meat', $row['category']);

        $tight = ShoppingText::parse('2lb chicken');
        self::assertSame('chicken', $tight['name']);
        self::assertSame('lb', $tight['unit']);
    }

    public function testBareNameAndCount(): void
    {
        $one = ShoppingText::parse('milk');
        self::assertSame('milk', $one['name']);
        self::assertSame(1.0, $one['quantity']);
        self::assertNull($one['unit']);
        self::assertSame('Dairy', $one['category']);

        $count = ShoppingText::parse('3 apples');
        self::assertSame('apples', $count['name']);
        self::assertSame(3.0, $count['quantity']);
        self::assertNull($count['unit']);
        self::assertSame('Produce', $count['category']);
    }

    public function testLongerAislePhraseWins(): void
    {
        self::assertSame('Frozen', ShoppingText::category('ice cream'));
        self::assertSame('Dairy', ShoppingText::category('sour cream'));
        self::assertSame('Other', ShoppingText::category('tape'));
        self::assertSame('Household', ShoppingText::categoryFor(null, 'paper towels'));
        self::assertSame('Household', ShoppingText::category('batteries'));
        self::assertSame('Household', ShoppingText::category('light bulbs'));
        self::assertSame('Pantry', ShoppingText::categoryFor('pantry', 'tape'));
    }

    public function testUnknownUnitAndEmptyText(): void
    {
        $this->expectException(ValidationException::class);
        ShoppingText::parse('   ');
    }

    public function testScopeAndAuditPath(): void
    {
        self::assertSame('shopping:write', Scope::forPath('/api/v1/shopping/items', true));
        self::assertSame('shopping:read', Scope::forPath('/api/v1/shopping/lists', false));
        self::assertContains('shopping:read', Scope::normalize(['read']));
        self::assertTrue(Scope::allows(['shopping:write'], 'shopping:read'));
        self::assertFalse(Scope::allows(['todos:write'], 'shopping:write'));
        self::assertSame(
            ['shopping_list', 'shopping_item'],
            Scope::auditEntityAllow(['shopping:read'], null)
        );
        self::assertSame('shopping_item', ApiRunner::entityFromPath('/api/v1/shopping/items/4/check'));
        self::assertSame('shopping_list', ApiRunner::entityFromPath('/api/v1/shopping/lists/2/clear-bought'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/shopping/items/4/check'));
        self::assertSame(2, ApiRunner::entityIdFromPath('/api/v1/shopping/lists/2/clear-bought'));
    }
}
