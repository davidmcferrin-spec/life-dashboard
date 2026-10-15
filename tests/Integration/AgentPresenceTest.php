<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Audit\AuditLog;
use Dashboard\Audit\TokenStamp;

final class AgentPresenceTest extends PgsqlTestCase
{
    public function testPresenceFeedIsAiOnlyAndOmitsDetail(): void
    {
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $user = (int) $this->db->lastInsertId();
        $audit = new AuditLog($this->db);
        $audit->record(
            $user,
            'ai',
            'list_transactions',
            'GET',
            '/api/v1/money/transactions',
            TokenStamp::detail(3, 'Household', 'read', ['notes' => 'do not show'])
        );
        $audit->record($user, 'user', 'list_transactions', 'GET', '/api/v1/money/transactions', ['notes' => 'session']);

        $rows = $audit->presence($user, 0, 20);
        self::assertCount(1, $rows);
        self::assertSame('Household', $rows[0]['agent']);
        self::assertSame('Money: list_transactions', $rows[0]['label']);
        self::assertStringNotContainsString('do not show', json_encode($rows, JSON_THROW_ON_ERROR));

        $again = $audit->presence($user, $rows[0]['id'], 20);
        self::assertSame([], $again);
    }
}
