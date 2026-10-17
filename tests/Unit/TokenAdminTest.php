<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use PHPUnit\Framework\TestCase;

final class TokenAdminTest extends TestCase
{
    public function testTokenChangeDetailKeepsTheNameAndDropsTheSecret(): void
    {
        $detail = ApiRunner::tokenChangeDetail([
            'deleted' => true,
            'previous_name' => 'Claude',
            'previous_scopes' => ['*', 'system:read'],
            'token' => [
                'id' => 7,
                'name' => 'Household',
                'prefix' => 'ld_abc',
                'scopes' => ['reddit:read', 'reddit:write', 'system:deploy'],
                'token' => 'ld_' . str_repeat('ab', 24),
                'token_hash' => str_repeat('c', 64),
            ],
        ]);
        self::assertSame(7, $detail['token_id']);
        self::assertSame('Household', $detail['token_name']);
        self::assertSame(['reddit:read', 'reddit:write', 'system:deploy'], $detail['scopes']);
        self::assertSame('Claude', $detail['previous_name']);
        self::assertSame(['*', 'system:read'], $detail['previous_scopes']);
        self::assertTrue($detail['deleted']);
        $encoded = json_encode($detail, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('ld_ab', $encoded);
        self::assertStringNotContainsString(str_repeat('c', 16), $encoded);
    }

    public function testTokenRoutesStayOnTheBrowserSession(): void
    {
        self::assertTrue(Scope::sessionOnly('/api/v1/tokens/4/update'));
        self::assertTrue(Scope::sessionOnly('/api/v1/tokens/4/delete'));
        self::assertTrue(Scope::sessionOnly('/api/v1/tokens/4/revoke'));
    }
}
