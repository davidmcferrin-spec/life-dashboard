<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Agents\AgentName;
use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AgentPresence;
use Dashboard\Handoff\HandoffInput;
use Dashboard\Handoff\PgArray;
use Dashboard\Support\Clock;
use Dashboard\Support\ForbiddenException;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class HandoffRulesTest extends TestCase
{
    public function testAgentNameMatchesTheHermesKeyLabel(): void
    {
        self::assertSame('MCP · Grok Bot v3', AgentName::mcpLabel("Grok\nBot v3"));
        self::assertSame('AI Agent (MCP)', AgentName::mcpLabel(''));
        self::assertSame('Penny', AgentName::identity(true, 'Penny', 'david'));
        self::assertSame('AI Agent (MCP)', AgentName::identity(true, '', 'david'));
        self::assertSame('david', AgentName::identity(false, 'Penny', 'david'));
    }

    public function testHandoffScopeIsItsOwnArea(): void
    {
        self::assertSame('handoffs:read', Scope::forPath('/api/v1/handoffs', false));
        self::assertSame('handoffs:write', Scope::forPath('/api/v1/handoffs/4/claim', true));
        self::assertSame(['handoffs:write', 'handoffs:read'], Scope::normalize(['handoffs:write', 'handoffs:read']));
        self::assertTrue(Scope::allows(['handoffs:write'], 'handoffs:read'));
        self::assertTrue(Scope::allows(['handoffs:read'], 'handoffs:read'));
        self::assertFalse(Scope::allows(['todos:read', 'todos:write'], 'handoffs:read'));
        self::assertFalse(Scope::allows(['handoffs:read'], 'todos:read'));
        self::assertSame(['handoff'], Scope::auditEntityAllow(['handoffs:read'], 'handoff'));
        self::assertSame('handoff', ApiRunner::entityFromPath('/api/v1/handoffs/4/status'));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/handoffs/4/comment'));
        self::assertSame(9, ApiRunner::entityIdFromPayload(['handoff' => ['id' => 9]]));
        try {
            Scope::auditEntityAllow(['handoffs:read'], 'todo');
            self::fail('a handoffs token cannot read todo audit rows');
        } catch (ForbiddenException $e) {
            self::assertStringContainsString('cannot read', $e->getMessage());
        }
    }

    public function testPresenceNamesTheKey(): void
    {
        $row = AgentPresence::describe([
            'id' => 3,
            'actor' => 'ai',
            'tool' => 'create_handoff',
            'action' => 'POST',
            'resource' => '/api/v1/handoffs',
            'detail' => ['token_name' => 'Penny'],
            'created_at' => '2026-11-22 18:00:00',
        ]);
        self::assertNotNull($row);
        self::assertSame('Handoffs', $row['area']);
        self::assertSame('Penny', $row['agent']);
        self::assertSame('create_handoff', $row['tool']);
    }

    public function testInputRules(): void
    {
        self::assertSame(['ops', 'home'], HandoffInput::tags(' Ops, home, ops '));
        self::assertSame([], HandoffInput::tags(null));
        self::assertSame([4, 9], HandoffInput::pageIds(['4', 9, '4']));
        self::assertSame(['https://example.com/a'], HandoffInput::urls([" https://example.com/a \n"]));
        self::assertSame('normal', HandoffInput::priority(null));
        self::assertSame('done', HandoffInput::closeStatus(''));
        self::assertNull(HandoffInput::agent(''));
        self::assertSame('Chief of Staff', HandoffInput::agent(' Chief of Staff '));

        $clock = new Clock('America/Chicago', new \DateTimeImmutable('2026-11-22 18:00:00', new \DateTimeZone('UTC')));
        self::assertSame('2026-11-23 05:59:59', HandoffInput::due($clock, '2026-11-22'));
        self::assertSame('2026-11-22 06:00:00', HandoffInput::since($clock, '2026-11-22'));

        try {
            HandoffInput::urls(['javascript:alert(1)']);
            self::fail('expected a link rejection');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('linked_urls', $e->fields());
        }
        try {
            HandoffInput::status('later');
            self::fail('expected a status rejection');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->fields());
        }
    }

    public function testPgArrayRoundTrip(): void
    {
        self::assertSame('{}', PgArray::text([]));
        self::assertSame('{}', PgArray::ints([]));
        $literal = PgArray::text(['ops', 'a"b']);
        self::assertSame(['ops', 'a"b'], PgArray::readText($literal));
        self::assertSame([4, 9], PgArray::readInts(PgArray::ints([4, 9])));
        self::assertSame([], PgArray::readText('{}'));
        self::assertSame(['ops', 'home'], PgArray::readText('{ops,home}'));
    }
}
