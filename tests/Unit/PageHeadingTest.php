<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PageHeadingTest extends TestCase
{
    public function testNamedPagesUseTheEyebrowAndTitle(): void
    {
        $cases = [
            'dashboard.php' => 'Personal intelligence system',
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
}
