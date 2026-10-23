<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ChromeCloseTest extends TestCase
{
    public function testTodoAndSetupCloseButtonsUseTheVendoredX(): void
    {
        $files = [
            VIEWS_DIR . '/dashboard.php',
            VIEWS_DIR . '/settings/setup.php',
            VIEWS_DIR . '/settings/todos.php',
            PUBLIC_DIR . '/assets/js/todos-page.js',
        ];
        foreach ($files as $file) {
            $src = file_get_contents($file);
            self::assertIsString($src, $file);
            self::assertStringContainsString('data-lucide-x', $src, $file);
            self::assertStringContainsString('/assets/vendor/lucide/x.svg', $src, $file);
            self::assertStringNotContainsString('×', $src, $file);
            self::assertStringNotContainsString('✕', $src, $file);
        }

        $svg = file_get_contents(PUBLIC_DIR . '/assets/vendor/lucide/x.svg');
        self::assertIsString($svg);
        self::assertStringContainsString('lucide-static v1.61.0', $svg);
        self::assertStringContainsString('M18 6 6 18', $svg);
        self::assertStringContainsString('m6 6 12 12', $svg);
    }
}
