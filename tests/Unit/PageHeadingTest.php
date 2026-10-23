<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PageHeadingTest extends TestCase
{
    public function testNamedPagesUseTheEyebrowAndTitle(): void
    {
        $cases = [
            'login.php' => 'Dashboard',
            'clock.php' => 'Clock',
            'settings/system.php' => 'System health',
            'settings/users.php' => 'Users',
        ];
        foreach ($cases as $view => $needle) {
            $html = file_get_contents(VIEWS_DIR . '/' . $view);
            self::assertIsString($html);
            self::assertStringContainsString('page-heading.php', $html);
            self::assertStringContainsString($needle, $html);
        }

        $login = file_get_contents(VIEWS_DIR . '/login.php');
        self::assertIsString($login);
        self::assertStringNotContainsString('Personal Dashboard', $login);
        self::assertStringContainsString("pageHeading = 'Sign in'", $login);
    }

    public function testDashboardDropsThePageHeadingAndTimezoneBar(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/dashboard.php');
        self::assertIsString($html);
        self::assertStringNotContainsString('page-heading.php', $html);
        self::assertStringNotContainsString('timezone-bar', $html);
        self::assertStringContainsString('id="search-form"', $html);

        $clock = file_get_contents(VIEWS_DIR . '/clock.php');
        self::assertIsString($clock);
        self::assertStringContainsString('page-heading.php', $clock);
        self::assertStringContainsString('Clock', $clock);
    }
}
