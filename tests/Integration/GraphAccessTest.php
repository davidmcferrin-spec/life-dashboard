<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Audit\AuditLog;
use Dashboard\Audit\TokenStamp;
use Dashboard\Knowledge\PageService;

final class GraphAccessTest extends PgsqlTestCase
{
    public function testGraphNodesCarryFolderAndAccessOmitsTitles(): void
    {
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $user = (int) $this->db->lastInsertId();
        $audit = new AuditLog($this->db);
        $pages = new PageService($this->db, $audit);

        $folderId = (int) $this->db->fetchScalar(
            'INSERT INTO page_folders (user_id, name) VALUES (?, ?) RETURNING id',
            [$user, 'Research']
        );
        $pageId = (int) $this->db->fetchScalar(
            'INSERT INTO pages (user_id, title, slug, kind, body, folder_id) VALUES (?, ?, ?, ?, ?, ?) RETURNING id',
            [$user, 'Flight notes', 'flight-notes', 'project', 'See [[Home]]', $folderId]
        );
        $homeId = (int) $this->db->fetchScalar(
            'INSERT INTO pages (user_id, title, slug, kind, body) VALUES (?, ?, ?, ?, ?) RETURNING id',
            [$user, 'Home', 'home', 'note', 'Start']
        );
        $this->db->execute(
            'INSERT INTO page_links (user_id, from_page_id, to_page_id, to_title) VALUES (?, ?, ?, ?)',
            [$user, $pageId, $homeId, 'Home']
        );

        $graph = $pages->graph($user);
        $byId = [];
        foreach ($graph['nodes'] as $node) {
            $byId[$node['id']] = $node;
        }
        self::assertSame($folderId, $byId[$pageId]['folder_id']);
        self::assertSame('Research', $byId[$pageId]['folder']);
        self::assertNotSame('', $byId[$pageId]['updated_at']);
        self::assertNull($byId[$homeId]['folder_id']);
        self::assertNotEmpty($graph['edges']);

        $audit->record($user, 'user', null, 'GET', 'page:' . $pageId, ['title' => 'Flight notes', 'verb' => 'read']);
        $audit->record(
            $user,
            'ai',
            'write_page',
            'POST',
            '/api/v1/pages/' . $homeId,
            TokenStamp::detail(3, 'Grok', 'write', ['title' => 'Home'])
        );
        $audit->record($user, 'ai', 'list_transactions', 'GET', '/api/v1/money/transactions', ['title' => 'skip']);

        $signals = $pages->graphAccess($user, 20);
        self::assertCount(2, $signals);
        $json = json_encode($signals, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Flight', $json);
        self::assertStringNotContainsString('Home', $json);
        $verbs = [];
        foreach ($signals as $row) {
            $verbs[$row['page_id']] = $row['verb'];
            self::assertArrayNotHasKey('title', $row);
        }
        self::assertSame('read', $verbs[$pageId]);
        self::assertSame('write', $verbs[$homeId]);
        $sources = array_column($signals, 'source');
        self::assertContains('You', $sources);
        self::assertContains('Grok', $sources);
    }
}
