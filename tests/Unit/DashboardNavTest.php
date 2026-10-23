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
            "['Flights', '/flights']",
            "['Packages', '/settings/packages']",
            'class="app-settings-pin',
            'class="app-settings-cog"',
            'app-settings-label">Settings</span>',
            'M9.56 2.2A10.1',
            'href="/logout"',
            'data-player-toggle',
            'id="live-date"',
            'id="app-nav-toggle"',
            'id="brain-show-map"',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringNotContainsString('M12 3.2v2.2', $html);
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
        self::assertStringContainsString('settings-kicker', $settingsTop);
        self::assertStringContainsString('settings-footer', $settingsBottom);
        self::assertStringContainsString('Changes saved automatically', $settingsBottom);

        $nav = file_get_contents(VIEWS_DIR . '/settings/_nav.php');
        self::assertIsString($nav);
        self::assertStringContainsString('settings-rail', $nav);
        self::assertStringContainsString('settings-step-num', $nav);
        self::assertStringContainsString("'Money'", $nav);
        self::assertStringContainsString("'Investing'", $nav);

        $flights = file_get_contents(VIEWS_DIR . '/flights.php');
        self::assertIsString($flights);
        self::assertStringNotContainsString('FLIGHT_PROVIDER', $flights);
        self::assertStringContainsString('flight-add-modal', $flights);
        self::assertStringNotContainsString('settings/_shell-top.php', $flights);

        $provider = file_get_contents(VIEWS_DIR . '/settings/flight-provider.php');
        self::assertIsString($provider);
        self::assertStringContainsString('FlightAware key', $provider);
        self::assertStringNotContainsString('FLIGHT_PROVIDER=', $provider);

        $shell = file_get_contents(ROOT_DIR . '/templates/partials/app-shell-open.php');
        self::assertIsString($shell);
        self::assertStringNotContainsString('href="/settings">Settings</a>', $shell);
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
