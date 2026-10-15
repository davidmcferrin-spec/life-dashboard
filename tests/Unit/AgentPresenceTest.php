<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Audit\AgentPresence;
use PHPUnit\Framework\TestCase;

final class AgentPresenceTest extends TestCase
{
    public function testBearerToolBecomesAnAreaLabel(): void
    {
        $money = AgentPresence::describe([
            'id' => 4,
            'actor' => 'ai',
            'tool' => 'list_transactions',
            'action' => 'GET',
            'resource' => '/api/v1/money/transactions',
            'detail' => ['token_name' => 'Household', 'notes' => 'secret note', 'payee' => '4111111111111111'],
            'created_at' => '2026-10-15 12:00:00',
        ]);
        self::assertNotNull($money);
        self::assertSame('Household', $money['agent']);
        self::assertSame('Money', $money['area']);
        self::assertSame('Money: list_transactions', $money['label']);
        self::assertSame(['id', 'agent', 'area', 'tool', 'label', 'at'], array_keys($money));
        self::assertStringNotContainsString('secret', json_encode($money, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('4111', json_encode($money, JSON_THROW_ON_ERROR));

        $reddit = AgentPresence::describe([
            'actor' => 'ai',
            'tool' => 'get_reddit_posts',
            'action' => 'GET',
            'resource' => '/api/v1/reddit/posts',
            'detail' => ['token_name' => 'Reader'],
        ]);
        self::assertSame('Reddit: get_reddit_posts', $reddit['label']);

        $vault = AgentPresence::describe([
            'actor' => 'ai',
            'tool' => 'write_page',
            'action' => 'POST',
            'resource' => '/api/v1/pages',
            'detail' => ['token_name' => 'Reader', 'title' => 'Pack list'],
        ]);
        self::assertSame('Vault: write_page', $vault['label']);
        self::assertStringNotContainsString('Pack', json_encode($vault, JSON_THROW_ON_ERROR));
    }

    public function testSessionTrafficAndAccountNumbersStayOut(): void
    {
        self::assertNull(AgentPresence::describe([
            'actor' => 'user',
            'tool' => 'list_transactions',
            'action' => 'GET',
            'resource' => '/api/v1/money/transactions',
            'detail' => [],
        ]));

        $named = AgentPresence::describe([
            'actor' => 'ai',
            'tool' => 'list_accounts',
            'action' => 'GET',
            'resource' => '/api/v1/money/accounts',
            'detail' => ['token_name' => '4111111111111111'],
        ]);
        self::assertSame('Agent', $named['agent']);
        self::assertSame('Money: list_accounts', $named['label']);
    }
}
