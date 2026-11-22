<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Money\PlaidClient;
use Dashboard\Money\StreamPlaidTransport;
use PHPUnit\Framework\TestCase;

final class PlaidRulesTest extends TestCase
{
    public function testPlaidSpendIsStoredAsANegativeAmount(): void
    {
        self::assertSame(-12.5, PlaidClient::signedAmount(12.5));
        self::assertSame(40.0, PlaidClient::signedAmount(-40));
        self::assertSame('Food And Drink', PlaidClient::categoryLabel([
            'personal_finance_category' => ['primary' => 'FOOD_AND_DRINK'],
        ]));
        self::assertSame('checking', PlaidClient::dashboardKind('depository', 'checking'));
        self::assertSame('credit', PlaidClient::dashboardKind('credit', 'credit card'));
        self::assertSame('Login required [redacted]', StreamPlaidTransport::safeMessage('ITEM_LOGIN_REQUIRED', 'Login required access-sandbox-abc'));
        self::assertStringNotContainsString('access-sandbox', StreamPlaidTransport::safeMessage('ITEM_LOGIN_REQUIRED', 'Login required access-sandbox-abc'));
    }

    public function testPlaidPathsStayOnTheMoneyAuditEntity(): void
    {
        self::assertSame('plaid', ApiRunner::entityFromPath('/api/v1/money/plaid/items/4/disconnect'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/money/plaid/items/4/disconnect'));
        self::assertSame(9, ApiRunner::entityIdFromPath('/api/v1/money/plaid/accounts/9/map'));
    }
}
