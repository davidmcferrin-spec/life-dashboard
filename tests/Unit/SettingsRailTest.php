<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SettingsRailTest extends TestCase
{
    public function testCloudClipboardViewsOmitTheSettingsColumn(): void
    {
        $boards = $this->render('settings/clipboards.php', '/settings/clipboards');
        $this->assertWorkspaceWithoutSettingsRail($boards, '/settings/clipboards');
        self::assertStringContainsString('id="clip-tab-boards"', $boards);
        self::assertStringContainsString('id="clip-board-list"', $boards);
        self::assertStringContainsString('id="clipboards-tab-panel"', $boards);
        self::assertStringNotContainsString('id="clipboards-tab-panel" class="hidden"', $boards);
        self::assertMatchesRegularExpression(
            '/href="\/settings\/clipboards"[^>]*aria-current="page"/',
            $boards
        );

        $files = $this->render('settings/clipboards.php', '/settings/clipboards?tab=files');
        $this->assertWorkspaceWithoutSettingsRail($files, '/settings/clipboards?tab=files');
        self::assertStringContainsString('id="clip-tab-files"', $files);
        self::assertStringContainsString('id="files-tab-panel"', $files);
        self::assertStringNotContainsString('id="files-tab-panel" class="hidden"', $files);
        self::assertStringContainsString('id="files-list"', $files);

        $cloudpad = $this->render('settings/clipboards.php', '/settings/clipboards?tab=cloudpad');
        $this->assertWorkspaceWithoutSettingsRail($cloudpad, '/settings/clipboards?tab=cloudpad');
        self::assertStringContainsString('id="clip-tab-cloudpad"', $cloudpad);
        self::assertStringContainsString('id="cloudpad-tab-panel"', $cloudpad);
        self::assertStringNotContainsString('id="cloudpad-tab-panel" class="hidden"', $cloudpad);
        self::assertStringContainsString('id="cloudpad-canvas-list"', $cloudpad);
        self::assertMatchesRegularExpression(
            '/href="\/settings\/clipboards\?tab=cloudpad"[^>]*aria-current="page"/',
            $cloudpad
        );
    }

    public function testOtherSettingsPagesKeepTheSettingsColumn(): void
    {
        $weather = $this->render('settings/weather.php', '/settings/weather');
        self::assertStringContainsString('id="app-sidebar"', $weather);
        self::assertStringContainsString('class="app-nav-text">Dashboard</span>', $weather);
        self::assertStringContainsString('class="settings-layout"', $weather);
        self::assertStringNotContainsString('settings-layout no-rail', $weather);
        self::assertStringContainsString('id="settings-rail"', $weather);
        self::assertStringContainsString('class="settings-step-name">Weather</span>', $weather);
        self::assertStringContainsString('class="settings-step-name">Cloud clipboards</span>', $weather);
        self::assertStringContainsString('aria-label="Settings"', $weather);

        $bookmarks = $this->render('settings/bookmarks.php', '/settings/bookmarks');
        self::assertStringContainsString('id="settings-rail"', $bookmarks);
        self::assertStringNotContainsString('settings-layout no-rail', $bookmarks);
        self::assertStringContainsString('class="settings-step-name">Bookmarks</span>', $bookmarks);
    }

    public function testNoRailLayoutFillsTheColumnWidth(): void
    {
        $css = file_get_contents(ROOT_DIR . '/public/assets/css/lifedash.css');
        self::assertIsString($css);
        self::assertMatchesRegularExpression(
            '/\.settings-layout\s*\{[^}]*grid-template-columns:\s*240px minmax\(0, 1fr\);/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.settings-layout\.no-rail\s*\{[^}]*grid-template-columns:\s*minmax\(0, 1fr\);/s',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.settings-layout\.no-rail > \.settings-panel\s*\{[^}]*padding-left:\s*0;/s',
            $css
        );
    }

    private function assertWorkspaceWithoutSettingsRail(string $html, string $uri): void
    {
        self::assertStringContainsString('id="app-sidebar"', $html, $uri);
        self::assertStringContainsString('class="app-nav-text">Dashboard</span>', $html, $uri);
        self::assertStringContainsString('class="app-nav-text">Clipboard</span>', $html, $uri);
        self::assertStringContainsString('class="app-nav-text">CloudPad</span>', $html, $uri);
        self::assertStringContainsString('class="app-settings-label">Settings</span>', $html, $uri);
        self::assertStringContainsString('class="settings-layout no-rail"', $html, $uri);
        self::assertStringContainsString('class="page-title">Cloud clipboards</h1>', $html, $uri);
        self::assertStringNotContainsString('id="settings-rail"', $html, $uri);
        self::assertStringNotContainsString('id="settings-rail-toggle"', $html, $uri);
        self::assertStringNotContainsString('settings-step-name', $html, $uri);
        self::assertStringNotContainsString('aria-label="Settings"', $html, $uri);
    }

    private function render(string $view, string $uri): string
    {
        $runner = sys_get_temp_dir() . '/settings-rail-' . bin2hex(random_bytes(4)) . '.php';
        $root = ROOT_DIR;
        $viewPath = VIEWS_DIR . '/' . $view;
        $uriExport = var_export($uri, true);
        $code = <<<PHP
<?php
declare(strict_types=1);
require '{$root}/tests/bootstrap.php';
if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        return 'test-csrf';
    }
}
\$_SERVER['REQUEST_URI'] = {$uriExport};
\$query = parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_QUERY);
\$_GET = [];
if (is_string(\$query) && \$query !== '') {
    parse_str(\$query, \$_GET);
}
\$user = ['username' => 'dm', 'is_admin' => 0];
\$themePref = 'dark';
\$starfieldPref = false;
\$clockFormat = '12h';
require '{$viewPath}';
PHP;
        file_put_contents($runner, $code);

        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runner) . ' 2>&1';
        $output = [];
        $status = 0;
        exec($command, $output, $status);
        @unlink($runner);

        $html = implode("\n", $output);
        self::assertSame(0, $status, $html);

        return $html;
    }
}
