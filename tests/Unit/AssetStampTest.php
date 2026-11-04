<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Support\AssetStamp;
use Dashboard\Support\Stylesheets;
use PHPUnit\Framework\TestCase;

final class AssetStampTest extends TestCase
{
    public function testStampIsTheNewestAssetMtime(): void
    {
        $stamp = AssetStamp::current();
        self::assertMatchesRegularExpression('/^[1-9]\d*$/', $stamp);
        $lifedash = filemtime(PUBLIC_DIR . '/assets/css/lifedash.css');
        self::assertNotFalse($lifedash);
        self::assertGreaterThanOrEqual($lifedash, (int) $stamp);
        self::assertSame($stamp, AssetStamp::current());
    }

    public function testFeatureSheetsKeepCascadeOrder(): void
    {
        $css = '';
        foreach (Stylesheets::app() as $path) {
            $full = PUBLIC_DIR . $path;
            self::assertFileExists($full);
            $css .= (string) file_get_contents($full) . "\n";
        }
        $board = strpos($css, '.ld-board');
        $vault = strpos($css, '.brain-shell');
        $shell = strpos($css, '.app-sidebar {');
        $player = strpos($css, '.sp-player {');
        $disc = strpos($css, '.sp-player .sp-record {');
        $menus = strpos($css, '.fin-table-actions');
        self::assertNotFalse($board);
        self::assertNotFalse($vault);
        self::assertNotFalse($shell);
        self::assertNotFalse($player);
        self::assertNotFalse($disc);
        self::assertNotFalse($menus);
        self::assertLessThan($vault, $board);
        self::assertLessThan($shell, $vault);
        self::assertLessThan($disc, $player);
        self::assertLessThan($menus, $disc);

        foreach (Stylesheets::media() as $path) {
            self::assertFileExists(PUBLIC_DIR . $path);
        }
    }
}
