<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class EmptyPanelTest extends TestCase
{
    public function testMissingPagesUseTheEmptyPanel(): void
    {
        $router = file_get_contents(SRC_DIR . '/Router/Router.php');
        self::assertIsString($router);
        self::assertStringContainsString("VIEWS_DIR . '/errors/404.php'", $router);
        self::assertStringNotContainsString('404 — Page not found', $router);

        $view = file_get_contents(VIEWS_DIR . '/errors/404.php');
        self::assertIsString($view);
        self::assertStringContainsString('empty-panel.php', $view);
        self::assertStringContainsString('Back to Dashboard', $view);

        $toast = file_get_contents(ROOT_DIR . '/public/assets/js/toast.js');
        self::assertIsString($toast);
        self::assertStringContainsString('window.alert = function', $toast);
        self::assertStringContainsString('4500', $toast);
        self::assertStringNotContainsString('window.confirm', $toast);
    }
}
