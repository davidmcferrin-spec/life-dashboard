<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardNavTest extends TestCase
{
    public function testPantrySitsWithVaultAndPlaylists(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/dashboard.php');
        self::assertIsString($html);
        self::assertMatchesRegularExpression(
            '/<div class="nav-meta flex items-center gap-3 flex-wrap justify-end">'
            . '.*<a href="\/brain" class="nav-link">Vault<\/a>\s*'
            . '<a href="\/spotify" class="nav-link">Playlists<\/a>\s*'
            . '<a href="\/pantry" class="nav-link">Pantry<\/a>\s*'
            . '<a href="\/digest" class="nav-link">Grok Digest<\/a>\s*'
            . '<a href="\/settings" class="nav-link">Settings<\/a>/s',
            $html
        );
    }

    public function testPhoneNavWrapsInsteadOfStretchingThePage(): void
    {
        $css = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');
        self::assertIsString($css);
        self::assertMatchesRegularExpression('/\.nav-meta\s*\{[^}]*flex-wrap:\s*wrap;[^}]*min-width:\s*0;/s', $css);

        foreach (['pantry.php', 'news.php', 'reddit.php', 'todos.php'] as $view) {
            $html = file_get_contents(VIEWS_DIR . '/' . $view);
            self::assertIsString($html);
            self::assertStringContainsString(
                'class="nav-meta flex items-center gap-3 flex-wrap justify-end min-w-0"',
                $html
            );
        }
    }
}
