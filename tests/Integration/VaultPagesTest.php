<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Audit\AuditLog;
use Dashboard\Knowledge\PageService;
use Dashboard\Support\ValidationException;

final class VaultPagesTest extends PgsqlTestCase
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

    public function testRenameRewritesLinksAndKeepsTheOldRevision(): void
    {
        $home = $this->pages->create($this->user, ['title' => 'Home', 'body' => 'start']);
        $trip = $this->pages->create($this->user, [
            'title' => 'Trip',
            'body' => "See [[Home|the house]] and [[Home#Door]].\n```\n[[Home]]\n```\n",
        ]);
        $renamed = $this->pages->update($this->user, (int) $home['id'], ['title' => 'House']);
        self::assertSame('House', $renamed['title']);

        $linked = $this->pages->get($this->user, (int) $trip['id']);
        self::assertStringContainsString('[[House|the house]]', $linked['body']);
        self::assertStringContainsString('[[House#Door]]', $linked['body']);
        self::assertStringContainsString("```\n[[Home]]\n```", $linked['body']);
        $byTitle = [];
        foreach ($linked['links'] as $link) {
            $byTitle[$link['title']] = $link;
        }
        self::assertSame((int) $home['id'], $byTitle['House']['id']);
        self::assertNull($byTitle['Home']['id']);
        self::assertSame('Trip', $renamed['backlinks'][0]['title']);

        $old = $this->db->fetchOne(
            'SELECT body FROM page_revisions WHERE page_id = ? AND version = 1',
            [(int) $trip['id']]
        );
        self::assertIsArray($old);
        self::assertStringContainsString('[[Home|the house]]', (string) $old['body']);
        $versions = (int) $this->db->fetchScalar(
            'SELECT COUNT(*) FROM page_revisions WHERE page_id = ?',
            [(int) $trip['id']]
        );
        self::assertSame(2, $versions);
    }

    public function testNestFavoriteRecentAndUnlinkedMention(): void
    {
        $home = $this->pages->create($this->user, ['title' => 'Home', 'body' => 'base']);
        $child = $this->pages->create($this->user, [
            'title' => 'Kitchen',
            'body' => 'Inside Home the kitchen.',
            'parent_id' => $home['id'],
        ]);
        self::assertSame((int) $home['id'], $child['parent_id']);
        self::assertSame('Kitchen', $this->pages->children($this->user, (int) $home['id'])[0]['title']);

        $grand = $this->pages->create($this->user, ['title' => 'Sink', 'body' => '']);
        $moved = $this->pages->setParent($this->user, (int) $grand['id'], (int) $child['id']);
        self::assertSame((int) $child['id'], $moved['parent_id']);
        try {
            $this->pages->setParent($this->user, (int) $home['id'], (int) $grand['id']);
            self::fail('a page cannot sit under its descendant');
        } catch (ValidationException $e) {
            self::assertStringContainsString('subpage', $e->getMessage());
        }

        $fav = $this->pages->setFavorite($this->user, (int) $home['id'], true);
        self::assertTrue($fav['favorite']);
        self::assertSame('Home', $this->pages->favorites($this->user)[0]['title']);
        $clear = $this->pages->setFavorite($this->user, (int) $home['id'], false);
        self::assertFalse($clear['favorite']);

        $this->pages->touch($this->user, (int) $child['id']);
        self::assertSame('Kitchen', $this->pages->opened($this->user, 5)[0]['title']);

        $mentions = $this->pages->mentionsFor($this->user, (int) $home['id']);
        self::assertSame('Kitchen', $mentions[0]['title']);

        $resolved = $this->pages->resolveLink($this->user, '[[Home#Door|house]]');
        self::assertSame((int) $home['id'], $resolved['page_id']);
        self::assertSame('Door', $resolved['heading']);
        self::assertSame('house', $resolved['alias']);
        self::assertNull($this->pages->resolveLink($this->user, '[[Missing page]]')['page_id']);

        $this->pages->archive($this->user, (int) $child['id']);
        $lifted = $this->pages->get($this->user, (int) $grand['id']);
        self::assertSame((int) $home['id'], $lifted['parent_id']);
    }
}
