<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Schema;
use Dashboard\Mcp\AgentPrompt;
use Dashboard\Pantry\FoodPreferences;
use PHPUnit\Framework\TestCase;

final class FoodPreferencesTest extends TestCase
{
    public function testHeadingsBecomeLikesAndDislikes(): void
    {
        $body = <<<MD
        ## Preferences
        - everything in here is not a like

        ## Likes
        - shrimp
        - [[San Marzano tomatoes|San Marzano]]
        - shrimp

        ## Don't buy
        - cilantro

        ```
        ## Avoid
        - fenced
        ```

        ## Avoid
        - **no oats**

        A paragraph is not an item.
        MD;
        $lists = FoodPreferences::listsFromMarkdown($body);
        self::assertSame(['shrimp', 'San Marzano'], $lists['likes']);
        self::assertSame(['cilantro', 'no oats'], $lists['dislikes']);
    }

    public function testAPageWithoutThoseHeadingsStaysUnparsed(): void
    {
        $lists = FoodPreferences::listsFromMarkdown("Buy the usual.\n\n- milk\n");
        self::assertSame([], $lists['likes']);
        self::assertSame([], $lists['dislikes']);
    }

    public function testShoppingMigrationCopiesKeysWithoutRewritingNotes(): void
    {
        $names = array_keys(Schema::pgsqlMigrationFiles());
        self::assertContains('0059_shopping_cart', $names);
        $sql = (string) file_get_contents(Schema::pgsqlMigrationsDir() . '/0059_shopping_cart.sql');
        self::assertStringContainsString('ADD COLUMN source', $sql);
        self::assertStringContainsString('ADD COLUMN dedupe_key', $sql);
        self::assertStringContainsString('ADD COLUMN needed_by', $sql);
        self::assertStringContainsString('ADD COLUMN order_wave', $sql);
        self::assertStringContainsString('uq_shopping_items_open_dedupe', $sql);
        self::assertStringNotContainsString('SET notes', $sql);
        self::assertStringNotContainsString('notes =', $sql);
        self::assertStringNotContainsString('DELETE FROM', $sql);
        self::assertStringNotContainsString('DROP ', $sql);
        $prompt = AgentPrompt::text();
        foreach (['get_food_preferences', 'dedupe_key', 'include_deferred', 'order_wave', 'needed_by'] as $needle) {
            self::assertStringContainsString($needle, $prompt);
        }
    }
}
