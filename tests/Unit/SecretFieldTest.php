<?php

declare(strict_types=1);

namespace {
    if (!function_exists('e')) {
        /**
         * The app helper from bootstrap.php. Templates call the global function.
         */
        function e(string $value): string
        {
            return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }
}

namespace Dashboard\Tests\Unit {

use Dashboard\Settings\SecretMask;
use PHPUnit\Framework\TestCase;

final class SecretFieldTest extends TestCase
{
    public function testMarkupNeverContainsTheStoredSecret(): void
    {
        $secret = 'supersecret99';
        $html = $this->render([
            'secretId' => 'api-key',
            'secretName' => 'api_key',
            'secretLabel' => 'API key',
            'secretHint' => SecretMask::hint($secret),
            'secretValue' => '',
            'secretPlaceholder' => '',
            'secretAutocomplete' => 'off',
            'secretHidden' => false,
        ]);

        self::assertStringNotContainsString($secret, $html);
        self::assertStringContainsString('id="api-key"', $html);
        self::assertStringContainsString('name="api_key"', $html);
        self::assertStringContainsString('type="password"', $html);
        self::assertStringContainsString('placeholder="•••••••• et99"', $html);
        self::assertStringNotContainsString('value="', $html);
        self::assertStringContainsString('Encrypted', $html);
        self::assertStringContainsString('data-secret-replace', $html);
        self::assertStringContainsString('lucide-lock-keyhole', $html);
        self::assertStringContainsString('lucide-eye', $html);
        self::assertStringContainsString('secret-field.js?v=20261023bb', $html);
        self::assertDoesNotMatchRegularExpression('/\bhidden\b/', $html);

        $again = $this->render([
            'secretId' => 'api-key-2',
            'secretName' => 'api_key',
            'secretLabel' => 'API key',
            'secretHint' => SecretMask::hint($secret),
            'secretValue' => '',
            'secretPlaceholder' => '',
            'secretAutocomplete' => 'off',
            'secretHidden' => false,
        ]);
        self::assertStringNotContainsString('secret-field.js', $again);
        self::assertStringNotContainsString($secret, $again);
    }

    public function testHiddenTunnelFieldKeepsTheCallerContract(): void
    {
        $secret = 'cf-tunnel-token-value';
        $html = $this->render([
            'secretId' => 'tunnel-token',
            'secretName' => 'token',
            'secretLabel' => '',
            'secretHint' => SecretMask::hint($secret),
            'secretValue' => '',
            'secretPlaceholder' => 'Paste a new token',
            'secretAutocomplete' => 'off',
            'secretHidden' => true,
        ]);

        self::assertStringNotContainsString($secret, $html);
        self::assertStringNotContainsString('<label', $html);
        self::assertMatchesRegularExpression(
            '/<input\b[^>]*\btype="password"[^>]*\bid="tunnel-token"[^>]*\bname="token"/s',
            $html
        );
        self::assertMatchesRegularExpression(
            '/<input\b[^>]*\bclass="input secret-field-input hidden"/',
            $html
        );
        self::assertStringContainsString('placeholder="Paste a new token"', $html);
        self::assertStringNotContainsString('value="', $html);
        self::assertStringNotContainsString('data-secret-replace', $html);
    }

    public function testTypedDraftIsTheOnlyValueAttribute(): void
    {
        $stored = 'supersecret99';
        $html = $this->render([
            'secretId' => 'api-key',
            'secretName' => 'api_key',
            'secretLabel' => 'API key',
            'secretHint' => SecretMask::hint($stored),
            'secretValue' => 'draft-from-the-form',
            'secretPlaceholder' => '',
            'secretAutocomplete' => 'off',
            'secretHidden' => false,
        ]);

        self::assertStringNotContainsString($stored, $html);
        self::assertStringContainsString('value="draft-from-the-form"', $html);
        self::assertStringNotContainsString('value="et99"', $html);
        self::assertStringNotContainsString('value="•••••••• et99"', $html);
    }

    public function testALongHintIsNotPrinted(): void
    {
        $leaked = 'this-is-the-whole-secret';
        $html = $this->render([
            'secretId' => 'token-field',
            'secretName' => 'token',
            'secretLabel' => 'Token',
            'secretHint' => $leaked,
            'secretValue' => '',
            'secretPlaceholder' => '',
            'secretAutocomplete' => 'off',
            'secretHidden' => false,
        ]);

        self::assertStringNotContainsString($leaked, $html);
        self::assertStringContainsString('placeholder="••••••••"', $html);
        self::assertStringNotContainsString('placeholder="•••••••• ' . $leaked . '"', $html);
    }

    public function testAnEmptyHintHasNoMaskAndNoReplace(): void
    {
        $html = $this->render([
            'secretId' => 'api-key-empty',
            'secretName' => 'api_key',
            'secretLabel' => 'API key',
            'secretHint' => '',
            'secretValue' => '',
            'secretPlaceholder' => '',
            'secretAutocomplete' => 'off',
            'secretHidden' => false,
        ]);

        self::assertStringNotContainsString('placeholder=', $html);
        self::assertStringNotContainsString('data-secret-replace', $html);
        self::assertStringContainsString('Encrypted', $html);
        self::assertStringContainsString('data-secret-show', $html);
    }

    public function testModalEnterAnimationRespectsReducedMotion(): void
    {
        $css = file_get_contents(ROOT_DIR . '/public/assets/css/lifedash.css');
        self::assertIsString($css);
        self::assertMatchesRegularExpression(
            '/@media \(prefers-reduced-motion: no-preference\)\s*\{[^}]*\.app-modal-panel\s*\{[^}]*animation:\s*modalIn\s+/s',
            $css
        );
        self::assertStringContainsString('@keyframes modalIn', $css);
        self::assertDoesNotMatchRegularExpression(
            '/@media \(prefers-reduced-motion: reduce\)\s*\{[^}]*animation:\s*modalIn/s',
            $css
        );
    }

    /**
     * @param array{secretId: string, secretName: string, secretLabel: string, secretHint: string, secretValue: string, secretPlaceholder: string, secretAutocomplete: string, secretHidden: bool} $vars
     */
    private function render(array $vars): string
    {
        $secretId = $vars['secretId'];
        $secretName = $vars['secretName'];
        $secretLabel = $vars['secretLabel'];
        $secretHint = $vars['secretHint'];
        $secretValue = $vars['secretValue'];
        $secretPlaceholder = $vars['secretPlaceholder'];
        $secretAutocomplete = $vars['secretAutocomplete'];
        $secretHidden = $vars['secretHidden'];
        $assetVer = '20261023bb';
        ob_start();
        try {
            require VIEWS_DIR . '/partials/secret-field.php';
            $html = ob_get_clean();
        } catch (\Throwable $error) {
            if (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $error;
        }
        self::assertIsString($html);

        return $html;
    }
}
}
