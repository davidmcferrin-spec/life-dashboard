<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FinanceNavTest extends TestCase
{
    public function testMoneyInvestingAndStocksAreTopLevelNavItems(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/partials/app-shell-open.php');
        self::assertIsString($html);
        foreach ([
            "['Money', '/money']",
            "['Investing', '/investing']",
            "['Stocks', '/stocks']",
            "'/money' => 'wallet-cards'",
            "'/investing' => 'trending-up'",
            "'/stocks' => 'chart-column'",
        ] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    public function testOlderSettingsUrlsRedirectToTheNewPages(): void
    {
        $routes = file_get_contents(ROOT_DIR . '/public/index.php');
        self::assertIsString($routes);
        self::assertStringContainsString("redirect('/money')", $routes);
        self::assertStringContainsString("redirect('/investing')", $routes);
        self::assertStringContainsString("redirect('/stocks')", $routes);

        self::assertSame("<?php\n\ndeclare(strict_types=1);\n\nredirect('/money');\n", (string) file_get_contents(VIEWS_DIR . '/settings/money.php'));
        self::assertSame("<?php\n\ndeclare(strict_types=1);\n\nredirect('/investing');\n", (string) file_get_contents(VIEWS_DIR . '/settings/investing.php'));
        self::assertSame("<?php\n\ndeclare(strict_types=1);\n\nredirect('/stocks');\n", (string) file_get_contents(VIEWS_DIR . '/settings/stocks.php'));
    }

    public function testCreatorLabelAssetVersionAndFunnelIconStayExact(): void
    {
        $money = file_get_contents(ROOT_DIR . '/public/assets/js/settings-money.js');
        self::assertIsString($money);
        self::assertStringContainsString('Creator subscription', $money);

        $head = file_get_contents(VIEWS_DIR . '/partials/head.php');
        self::assertIsString($head);
        self::assertStringContainsString("\$assetVer = '20261023br';", $head);

        $funnel = file_get_contents(ROOT_DIR . '/public/assets/vendor/lucide/funnel.svg');
        self::assertIsString($funnel);
        self::assertStringContainsString('lucide-static v1.61.0 - ISC', $funnel);
        self::assertStringContainsString('class="lucide lucide-funnel"', $funnel);
    }
}
