<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Auth\ApiTokenService;
use Dashboard\Money\AccountNumberGuard;
use Dashboard\Money\BudgetImporter;
use Dashboard\Money\MoneyPlanService;
use Dashboard\Money\MoneyService;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class MoneyPlanTest extends TestCase
{
    public function testAccountNumbersAndTheCreatorAlias(): void
    {
        self::assertTrue(AccountNumberGuard::hasFullNumber('paid 123456789012 today'));
        self::assertFalse(AccountNumberGuard::hasFullNumber('****1234'));
        self::assertFalse(AccountNumberGuard::hasFullNumber('2026-10-14'));
        self::assertFalse(AccountNumberGuard::hasFullNumber('$1,234.56'));
        self::assertSame('1234', AccountNumberGuard::last4('****1234'));
        self::assertSame(
            'Pay Creator subscription',
            AccountNumberGuard::alias('Pay StreamCo', 'StreamCo')
        );
        self::assertStringNotContainsString('123456789012', (string) AccountNumberGuard::redact('acct 123456789012'));
        self::assertStringContainsString('[redacted]', (string) AccountNumberGuard::redact('acct 123456789012'));
    }

    public function testHeaderMatchNamesTheFileHeadersWhenAColumnIsMissing(): void
    {
        try {
            BudgetImporter::matchHeaders(
                ['When', 'Wallet', 'Signed'],
                ['date' => ['date'], 'account' => ['account'], 'payee' => ['payee']],
                ['date', 'account', 'payee']
            );
            self::fail('expected a header mismatch');
        } catch (ValidationException $e) {
            self::assertStringContainsString('When', $e->getMessage());
            self::assertStringContainsString('date', $e->getMessage());
        }
    }

    public function testAMapCanRenameAColumn(): void
    {
        $found = BudgetImporter::matchHeaders(
            ['Txn day', 'Wallet'],
            ['date' => ['date'], 'account' => ['account']],
            ['date', 'account'],
            ['date' => 'Txn day', 'account' => 'Wallet']
        );
        self::assertSame(['date' => 'txn day', 'account' => 'wallet'], $found);
    }

    public function testPayoffEstimateStopsWhenThePaymentDoesNotCoverInterest(): void
    {
        self::assertNull(MoneyPlanService::estimatePayoff(10000, 20, 10, '2026-10-14'));
        self::assertSame('2026-10-14', MoneyPlanService::estimatePayoff(0, 6.99, 200, '2026-10-14'));
        $estimate = MoneyPlanService::estimatePayoff(10000, 6.99, 200, '2026-10-14');
        self::assertNotNull($estimate);
        self::assertGreaterThan('2026-10-14', $estimate);
    }

    public function testDedupeKeyIgnoresCaseAndCentRounding(): void
    {
        self::assertSame(
            MoneyService::dedupeKey('2026-10-13', -12.5, 'Checking', 'Cafe'),
            MoneyService::dedupeKey('2026-10-13', -12.50, ' checking ', 'Cafe')
        );
    }

    public function testMoneyFilterDropsAccountNumbers(): void
    {
        $filter = ApiRunner::moneyFilter([
            'from'     => '2026-10-01',
            'category' => 'Food',
            'account'  => '123456789012',
            'note'     => 'secret',
        ]);
        self::assertStringContainsString('from=2026-10-01', $filter);
        self::assertStringContainsString('category=Food', $filter);
        self::assertStringNotContainsString('123456789012', $filter);
        self::assertStringNotContainsString('note', $filter);
    }

    public function testMoneyOnlyTokensAreTheMoneyScopesAlone(): void
    {
        self::assertTrue(ApiTokenService::isMoneyOnly(['money:read', 'money:write']));
        self::assertTrue(ApiTokenService::isMoneyOnly(['money:read']));
        self::assertFalse(ApiTokenService::isMoneyOnly(['*']));
        self::assertFalse(ApiTokenService::isMoneyOnly([]));
        self::assertFalse(ApiTokenService::isMoneyOnly(['money:read', 'pages:read']));
        self::assertTrue(ApiTokenService::isTodosOnly(['todos:read', 'todos:write']));
        self::assertFalse(ApiTokenService::isTodosOnly(['todos:read', 'money:read']));
        self::assertNull(Scope::auditEntityAllow(null, 'debt'));
        try {
            Scope::auditEntityAllow(['money:read'], 'todo');
            self::fail('a money token cannot read todo audit rows');
        } catch (\Dashboard\Support\ForbiddenException) {
            self::assertTrue(true);
        }
        self::assertSame(
            ['debt', 'account', 'transaction', 'budget', 'sinking_fund'],
            Scope::auditEntityAllow(['money:read', 'money:write'], null)
        );
        self::assertFalse(Scope::allows(['money:read'], 'money:write'));
        self::assertTrue(Scope::allows(['money:write'], 'money:read'));
    }
}
