<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DarkThemeAccentTest extends TestCase
{
    public function testDarkThemeAccentIsDesignGreyAndJarvisAndLightStayTeal(): void
    {
        $dashboard = file_get_contents(ROOT_DIR . '/public/assets/css/dashboard.css');
        self::assertIsString($dashboard);

        $dark = self::themeBlock($dashboard, 'dark');
        $light = self::themeBlock($dashboard, 'light');
        $jarvis = self::themeBlock($dashboard, 'jarvis');

        self::assertSame('#9ab4b5', self::token($dark, '--accent'));
        self::assertSame('#bacccd', self::token($dark, '--accent-hover'));
        self::assertSame('rgba(154, 180, 181, 0.11)', self::token($dark, '--accent-soft'));
        self::assertStringNotContainsString('--hud-accent', $dark);
        self::assertStringNotContainsString('#44d7d0', $dark);
        self::assertStringNotContainsString('#168c88', $dark);

        self::assertSame('#168c88', self::token($light, '--accent'));
        self::assertSame('#137975', self::token($light, '--accent-hover'));
        self::assertSame('rgba(22, 140, 136, 0.10)', self::token($light, '--accent-soft'));
        self::assertStringNotContainsString('#9ab4b5', $light);
        self::assertStringNotContainsString('--hud-accent', $light);

        self::assertSame('var(--hud-accent, #44d7d0)', self::token($jarvis, '--accent'));
        self::assertSame('var(--hud-bright, #80e4df)', self::token($jarvis, '--accent-hover'));
        self::assertStringContainsString('--hud-accent', $jarvis);
        self::assertStringNotContainsString('#9ab4b5', $jarvis);

        $shell = file_get_contents(ROOT_DIR . '/public/assets/css/lifedash.css');
        self::assertIsString($shell);
        self::assertSame('#44d7d0', self::token(self::ruleBlock($shell, '.settings-shell', true), '--settings-accent'));
        self::assertSame('#168c88', self::token(self::ruleBlock($shell, 'html[data-theme="light"] .settings-shell'), '--settings-accent'));
        self::assertSame('#9ab4b5', self::token(self::ruleBlock($shell, 'html[data-theme="dark"] .settings-shell'), '--settings-accent'));
        self::assertSame(
            'rgba(154, 180, 181, 0.11)',
            self::token(self::ruleBlock($shell, 'html[data-theme="dark"] .settings-shell'), '--settings-accent-soft')
        );
    }

    private static function themeBlock(string $css, string $theme): string
    {
        $pattern = '/html\[data-theme="' . $theme . '"\]\s*\{([^}]+)\}/';
        self::assertSame(1, preg_match($pattern, $css, $match));

        return $match[1];
    }

    private static function ruleBlock(string $css, string $selector, bool $lineStart = false): string
    {
        $anchor = $lineStart ? '(?m)^' : '';
        $pattern = '/' . $anchor . preg_quote($selector, '/') . '\s*\{([^}]+)\}/';
        self::assertSame(1, preg_match($pattern, $css, $match));

        return $match[1];
    }

    private static function token(string $block, string $name): string
    {
        $pattern = '/' . preg_quote($name, '/') . '\s*:\s*([^;]+);/';
        self::assertSame(1, preg_match($pattern, $block, $match));

        return trim($match[1]);
    }
}
