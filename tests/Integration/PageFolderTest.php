<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Audit\AuditLog;
use Dashboard\Knowledge\PageService;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

final class PageFolderTest extends PgsqlTestCase
{
    private int $user;

    private PageService $pages;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrate();
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['ada', 'ada@x', 'h']
        );
        $this->user = (int) $this->db->lastInsertId();
        $this->pages = new PageService($this->db, new AuditLog($this->db));
    }

    public function testFoldersNestMoveAndFilterSearch(): void
    {
        $root = $this->pages->create($this->user, ['title' => 'Loose', 'body' => 'at root']);
        self::assertNull($root['folder_id']);
        self::assertSame([], $root['breadcrumbs']);

        $travel = $this->pages->createFolder($this->user, 'Travel', null);
        $year = $this->pages->createFolder($this->user, '2026', (int) $travel['id']);
        $page = $this->pages->create($this->user, [
            'title' => 'Pack list',
            'body' => 'See [[Loose]]',
            'folder' => 'Travel/2026',
        ]);
        self::assertSame((int) $year['id'], $page['folder_id']);
        self::assertSame(['Travel', '2026'], array_column($page['breadcrumbs'], 'name'));

        $tree = $this->pages->folderTree($this->user);
        self::assertSame('Travel', $tree[0]['name']);
        self::assertSame('2026', $tree[0]['children'][0]['name']);

        $found = $this->pages->list($this->user, 'pack', 20, (int) $travel['id'], true);
        self::assertSame('Pack list', $found[0]['title']);
        $miss = $this->pages->list($this->user, 'pack', 20, null, true);
        self::assertSame([], $miss);

        $this->pages->movePage($this->user, (int) $page['id'], null);
        $moved = $this->pages->get($this->user, (int) $page['id']);
        self::assertNull($moved['folder_id']);

        try {
            $this->pages->deleteFolder($this->user, (int) $travel['id'], false);
            self::fail('expected a non-empty folder to be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('not empty', $e->getMessage());
        }
        $this->pages->moveFolder($this->user, (int) $year['id'], null);
        $renamed = $this->pages->renameFolder($this->user, (int) $year['id'], 'Season');
        self::assertSame('Season', $renamed['name']);
        self::assertNull($renamed['parent_id']);
        $this->pages->deleteFolder($this->user, (int) $travel['id'], true);
        self::assertSame(0, (int) $this->db->fetchScalar('SELECT COUNT(*) FROM page_folders WHERE id = ?', [(int) $travel['id']]));

        $audit = $this->db->fetchOne(
            "SELECT action, resource FROM audit_events WHERE user_id = ? AND action = 'folder.create' ORDER BY id ASC LIMIT 1",
            [$this->user]
        );
        self::assertNotNull($audit);
        self::assertStringStartsWith('folder:', (string) $audit['resource']);

        try {
            $this->pages->folderIdByPath($this->user, 'Missing/Place');
            self::fail('expected a missing folder');
        } catch (NotFoundException) {
            self::assertTrue(true);
        }
    }

    public function testNestingStopsAtEightAndAMoveCannotExceedIt(): void
    {
        $parent = null;
        for ($i = 1; $i <= 6; $i++) {
            $folder = $this->pages->createFolder($this->user, 'L' . $i, $parent);
            $parent = (int) $folder['id'];
        }
        $branch = $this->pages->createFolder($this->user, 'A', null);
        $mid = $this->pages->createFolder($this->user, 'B', (int) $branch['id']);
        $this->pages->createFolder($this->user, 'C', (int) $mid['id']);
        try {
            $this->pages->moveFolder($this->user, (int) $branch['id'], $parent);
            self::fail('expected a move that would pass eight levels to be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('8 levels', $e->getMessage());
        }
        $deep = $parent;
        for ($i = 7; $i <= 8; $i++) {
            $folder = $this->pages->createFolder($this->user, 'L' . $i, $deep);
            $deep = (int) $folder['id'];
        }
        try {
            $this->pages->createFolder($this->user, 'L9', $deep);
            self::fail('expected a ninth level to be refused');
        } catch (ValidationException $e) {
            self::assertStringContainsString('8 levels', $e->getMessage());
        }
    }
}
