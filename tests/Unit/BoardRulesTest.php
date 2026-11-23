<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\ApiRunner;
use Dashboard\Api\V1\Scope;
use Dashboard\Audit\AgentPresence;
use Dashboard\Board\Fields;
use Dashboard\Support\ValidationException;
use Dashboard\Videos\VideoSlateService;
use PHPUnit\Framework\TestCase;

final class BoardRulesTest extends TestCase
{
    public function testScopesAreListedAsTheirOwnAreas(): void
    {
        self::assertSame('digest:write', Scope::forPath('/api/v1/digests', true));
        self::assertSame('trips:read', Scope::forPath('/api/v1/trips', false));
        self::assertSame('trips:write', Scope::forPath('/api/v1/trips/4/template', true));
        self::assertSame('videos:write', Scope::forPath('/api/v1/videos', true));
        self::assertSame('findings:read', Scope::forPath('/api/v1/findings', false));
        self::assertSame('calls:write', Scope::forPath('/api/v1/calls/contacts/add', true));
        self::assertContains('trips', Scope::AREAS);
        self::assertContains('videos', Scope::AREAS);
        self::assertContains('findings', Scope::AREAS);
        self::assertSame(['trip'], Scope::auditEntityAllow(['trips:read'], 'trip'));
        self::assertSame('news_digest', ApiRunner::entityFromPath('/api/v1/digests'));
        self::assertSame('trip', ApiRunner::entityFromPath('/api/v1/trips/3'));
        self::assertSame('video', ApiRunner::entityFromPath('/api/v1/videos/2'));
        self::assertSame('finding', ApiRunner::entityFromPath('/api/v1/findings'));
        self::assertSame('contact', ApiRunner::entityFromPath('/api/v1/calls/contacts/add'));
        self::assertSame(9, ApiRunner::entityIdFromPayload(['digest' => ['id' => 9]]));
        self::assertSame(4, ApiRunner::entityIdFromPath('/api/v1/trips/4/template'));
        self::assertFalse(Scope::allows(['digest:write'], 'trips:read'));
    }

    public function testHeadlinesAndStatuses(): void
    {
        self::assertSame(['One', 'Two'], Fields::headlines([' One ', ['text' => 'Two'], '']));
        self::assertSame('scripted', Fields::status('scripted', VideoSlateService::STATUSES, 'idea'));
        self::assertSame('idea', Fields::status(null, VideoSlateService::STATUSES, 'idea'));
        try {
            Fields::headlines([]);
            self::fail('expected a headline rejection');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('items', $e->fields());
        }
        try {
            Fields::status('later', VideoSlateService::STATUSES);
            self::fail('expected a status rejection');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('status', $e->fields());
        }
    }

    public function testMigrationAddsTheBatchTables(): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__, 2) . '/database/pgsql/0064_bot_batch.sql');
        foreach (['CREATE TABLE digests', 'CREATE TABLE trips', 'CREATE TABLE video_slate', 'CREATE TABLE research_findings', 'ADD COLUMN trip_id', 'ADD COLUMN placed_by', 'ADD COLUMN emails'] as $needle) {
            self::assertStringContainsString($needle, $sql);
        }
    }

    public function testPresenceNamesTheBoards(): void
    {
        $row = AgentPresence::describe([
            'id' => 8,
            'actor' => 'ai',
            'tool' => 'publish_digest',
            'action' => 'POST',
            'resource' => '/api/v1/digests',
            'detail' => ['token_name' => 'Space Desk'],
            'created_at' => '2026-11-23 00:00:00',
        ]);
        self::assertNotNull($row);
        self::assertSame('Briefs', $row['area']);
        self::assertSame('Space Desk', $row['agent']);
    }
}
