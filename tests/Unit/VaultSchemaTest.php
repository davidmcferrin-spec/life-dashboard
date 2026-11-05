<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\Schema;
use PHPUnit\Framework\TestCase;

final class VaultSchemaTest extends TestCase
{
    public function testVaultMigrationAddsNestingWithoutTouchingRevisions(): void
    {
        $names = array_keys(Schema::pgsqlMigrationFiles());
        self::assertContains('0055_vault_pages', $names);
        $sql = (string) file_get_contents(Schema::pgsqlMigrationsDir() . '/0055_vault_pages.sql');
        self::assertStringContainsString('ADD COLUMN parent_id', $sql);
        self::assertStringContainsString('ADD COLUMN favorited_at', $sql);
        self::assertStringContainsString('ADD COLUMN last_opened_at', $sql);
        self::assertStringContainsString('pages_parent_not_self', $sql);
        self::assertStringContainsString('REFERENCES pages(id) ON DELETE SET NULL', $sql);
        self::assertStringNotContainsString('page_revisions', $sql);
        self::assertStringNotContainsString('DROP TABLE', $sql);
        self::assertStringNotContainsString('DELETE FROM', $sql);
        $images = (string) file_get_contents(Schema::pgsqlMigrationsDir() . '/0056_page_images.sql');
        self::assertStringContainsString('CREATE TABLE page_images', $images);
        self::assertStringContainsString("CHECK (ext IN ('jpg', 'png', 'gif', 'webp'))", $images);
        self::assertStringNotContainsString('share', strtolower($images));
    }
}
