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
            'vendor/lucide/settings.svg',
            'href="/logout"',
            'data-player-toggle',
            'id="live-date"',
            'id="app-nav-toggle"',
            'id="brain-show-map"',
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringNotContainsString('M12 3.2v2.2', $html);
        self::assertStringNotContainsString('M9.56 2.2A10.1', $html);

        $gear = file_get_contents(ROOT_DIR . '/public/assets/vendor/lucide/settings.svg');
        self::assertIsString($gear);
        self::assertStringContainsString('class="lucide lucide-settings"', $gear);
        self::assertStringContainsString('M9.671 4.136a2.34', $gear);
        self::assertStringContainsString('stroke="currentColor"', $gear);
        self::assertStringContainsString('stroke-width="2"', $gear);
        self::assertStringContainsString('lucide-static v1.61.0 - ISC', $gear);
        self::assertStringNotContainsString('M9.56 2.2A10.1', $gear);
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
        self::assertStringContainsString('Saved automatically', $settingsBottom);
        self::assertStringContainsString('Unsaved changes', $settingsBottom);
        self::assertStringNotContainsString('Changes saved automatically', $settingsBottom);
        self::assertStringContainsString("settings_shell_icon('chevron-left')", $settingsBottom);

        $nav = file_get_contents(VIEWS_DIR . '/settings/_nav.php');
        self::assertIsString($nav);
        self::assertStringContainsString('settings-rail', $nav);
        self::assertStringContainsString('settings-step-num', $nav);
        self::assertStringContainsString("settings_shell_icon('circle-dot')", $nav);
        self::assertStringContainsString("'Money'", $nav);
        self::assertStringContainsString("'Investing'", $nav);
        self::assertStringNotContainsString("'Identity'", $nav);
        self::assertStringNotContainsString("'Initialize'", $nav);

        $chevron = file_get_contents(ROOT_DIR . '/public/assets/vendor/lucide/chevron-left.svg');
        self::assertIsString($chevron);
        self::assertStringContainsString('class="lucide lucide-chevron-left"', $chevron);
        self::assertStringContainsString('d="m15 18-6-6 6-6"', $chevron);
        self::assertStringContainsString('stroke="currentColor"', $chevron);
        self::assertStringContainsString('lucide-static v1.61.0 - ISC', $chevron);

        $dot = file_get_contents(ROOT_DIR . '/public/assets/vendor/lucide/circle-dot.svg');
        self::assertIsString($dot);
        self::assertStringContainsString('class="lucide lucide-circle-dot"', $dot);
        self::assertStringContainsString('r="10"', $dot);
        self::assertStringContainsString('stroke="currentColor"', $dot);
        self::assertStringContainsString('lucide-static v1.61.0 - ISC', $dot);

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

    public function testSearchStaysFixedAboveTheWidgetGrid(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/dashboard.php');
        self::assertIsString($html);
        $searchAt = strpos($html, 'class="ld-search-row"');
        $formAt = strpos($html, 'id="search-form"');
        $gridAt = strpos($html, 'id="ld-grid"');
        $endAt = strpos($html, 'id="ld-initial"');
        self::assertNotFalse($searchAt);
        self::assertNotFalse($formAt);
        self::assertNotFalse($gridAt);
        self::assertNotFalse($endAt);
        self::assertGreaterThan($searchAt, $formAt);
        self::assertGreaterThan($formAt, $gridAt);
        self::assertGreaterThan($gridAt, $endAt);
        $searchRow = substr($html, $searchAt, $gridAt - $searchAt);
        self::assertStringContainsString('ld-search-box', $searchRow);
        self::assertStringContainsString('search-engine-btn', $searchRow);
        self::assertStringContainsString('How can I assist you?', $searchRow);
        self::assertStringNotContainsString('grid-stack-item', $searchRow);
        self::assertStringNotContainsString('data-widget-id', $searchRow);
        self::assertStringNotContainsString('data-action="remove"', $searchRow);
        self::assertStringNotContainsString('gs-no-move', $searchRow);
        self::assertStringNotContainsString('data-hero', $html);
        self::assertStringNotContainsString('ov-layout', $html);

        $css = file_get_contents(ROOT_DIR . '/public/assets/css/lifedash.css');
        self::assertIsString($css);
        self::assertStringNotContainsString('minmax(0, 1fr) 320px', $css);
        self::assertMatchesRegularExpression(
            '/#search-form\.ov-composer\.ld-search-box\s*\{[^}]*margin-left:\s*auto;[^}]*margin-right:\s*auto;[^}]*max-width:\s*42rem;/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.ld-search-row\s*\{[^}]*width:\s*100%;/s',
            $css
        );

        $js = file_get_contents(ROOT_DIR . '/public/assets/js/lifedash-grid.js');
        self::assertIsString($js);
        self::assertStringNotContainsString('function heroPlan', $js);
        self::assertStringNotContainsString('function storedY', $js);
        self::assertStringNotContainsString('heroOffset', $js);
        self::assertStringContainsString('function fillWidth', $js);
        self::assertStringNotContainsString('editBtn.disabled = phone', $js);
        self::assertStringContainsString('function saveMobileOrder', $js);
        self::assertStringContainsString('The search box sits above this grid. It is not a widget.', $js);
    }
}
