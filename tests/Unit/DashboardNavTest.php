<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DashboardNavTest extends TestCase
{
    public function testSidebarMapsRealRoutes(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/partials/app-shell-open.php');
        self::assertIsString($html);
        foreach ([
            "['Dashboard', '/']",
            "['Vault', '/brain']",
            "['Brain', '/brain/graph']",
            "['Grok Digest', '/digest']",
            "['News', '/news']",
            "['Reddit', '/reddit']",
            "['Agents', '/settings/agents']",
            "['Playlists', '/spotify']",
            "['Todos', '/todos']",
            "['Clock', '/clock']",
            "['Pantry', '/pantry']",
            "['Meals', '/pantry?view=meals']",
            "['Flights', '/settings/flights']",
            "['Packages', '/settings/packages']",
            'href="/settings"',
            'href="/logout"',
            'data-player-toggle',
            'id="live-date"',
            'id="app-nav-toggle"',
            'id="brain-show-map"',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    public function testPhoneDrawerDoesNotStretchTheHeader(): void
    {
        $shell = file_get_contents(ROOT_DIR . '/public/assets/css/lifedash.css');
        self::assertIsString($shell);
        self::assertMatchesRegularExpression(
            '/@media \(max-width: 699px\)\s*\{.*?\.app-sidebar\s*\{[^}]*position:\s*fixed;/s',
            $shell
        );

        $css = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');
        self::assertIsString($css);
        self::assertMatchesRegularExpression('/\.nav-meta\s*\{[^}]*flex-wrap:\s*wrap;[^}]*min-width:\s*0;/s', $css);

        $header = file_get_contents(VIEWS_DIR . '/partials/app-shell-open.php');
        self::assertIsString($header);
        self::assertStringContainsString('class="nav-meta app-header-tools"', $header);

        foreach ([
            'dashboard.php',
            'pantry.php',
            'news.php',
            'reddit.php',
            'todos.php',
            'brain.php',
            'brain-graph.php',
            'clock.php',
            'digest.php',
            'spotify.php',
            'cloudpad/canvas.php',
        ] as $view) {
            $html = file_get_contents(VIEWS_DIR . '/' . $view);
            self::assertIsString($html);
            self::assertStringContainsString('app-shell-open.php', $html);
            self::assertStringContainsString('app-shell-close.php', $html);
        }

        $settingsTop = file_get_contents(VIEWS_DIR . '/settings/_shell-top.php');
        $settingsBottom = file_get_contents(VIEWS_DIR . '/settings/_shell-bottom.php');
        self::assertIsString($settingsTop);
        self::assertIsString($settingsBottom);
        self::assertStringContainsString('app-shell-open.php', $settingsTop);
        self::assertStringContainsString('app-shell-close.php', $settingsBottom);
    }

    public function testWidgetGridStaysOnTheDashboard(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/dashboard.php');
        self::assertIsString($html);
        self::assertStringContainsString('id="ld-grid"', $html);
        self::assertStringContainsString('aria-label="Dashboard widgets"', $html);
        self::assertDoesNotMatchRegularExpression('/href="\/systems"/', $html);
    }
}
