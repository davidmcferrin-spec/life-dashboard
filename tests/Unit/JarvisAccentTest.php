<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Support\JarvisAccent;
use PHPUnit\Framework\TestCase;

final class JarvisAccentTest extends TestCase
{
    public function testNormalizeAcceptsHexAndFallsBackToArcReactorCyan(): void
    {
        self::assertSame('#00e5ff', JarvisAccent::normalize('#00E5FF'));
        self::assertSame('#00e5ff', JarvisAccent::normalize('00e5ff'));
        self::assertSame('#c45c26', JarvisAccent::normalize('  #C45C26 '));
        self::assertSame(JarvisAccent::DEFAULT, JarvisAccent::normalize(null));
        self::assertSame(JarvisAccent::DEFAULT, JarvisAccent::normalize('red'));
        self::assertSame(JarvisAccent::DEFAULT, JarvisAccent::normalize('#fff'));
        self::assertSame(JarvisAccent::DEFAULT, JarvisAccent::normalize('#00e5ff00'));
    }

    public function testIsValidRequiresSixDigitHex(): void
    {
        self::assertTrue(JarvisAccent::isValid('#00e5ff'));
        self::assertTrue(JarvisAccent::isValid('C45C26'));
        self::assertFalse(JarvisAccent::isValid('#00e5'));
        self::assertFalse(JarvisAccent::isValid('rgb(0,229,255)'));
        self::assertFalse(JarvisAccent::isValid(''));
    }

    public function testVariantsDeriveChannelsBrightDimAndInk(): void
    {
        $cyan = JarvisAccent::variants('#00e5ff');
        self::assertSame('#00e5ff', $cyan['accent']);
        self::assertSame('0,229,255', $cyan['rgb']);
        self::assertSame('#52edff', $cyan['bright']);
        self::assertSame('#028397', $cyan['dim']);
        self::assertSame('#061018', $cyan['ink']);

        $gold = JarvisAccent::variants('#e8b15a');
        self::assertSame('232,177,90', $gold['rgb']);
        self::assertSame('#f4fbff', $gold['ink']);

        $blood = JarvisAccent::variants('#8b1e1e');
        self::assertSame('#f4fbff', $blood['ink']);
    }

    public function testCssDeclarationIsOnlyCustomProperties(): void
    {
        $css = JarvisAccent::cssDeclaration('00e5ff');
        self::assertSame(
            '--hud-accent:#00e5ff;--hud-rgb:0,229,255;--hud-bright:#52edff;--hud-dim:#028397;--hud-ink:#061018;',
            $css
        );
    }
}
