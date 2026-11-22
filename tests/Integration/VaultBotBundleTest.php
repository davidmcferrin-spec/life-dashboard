<?php

declare(strict_types=1);

namespace Dashboard\Tests\Integration;

use Dashboard\Audit\AuditLog;
use Dashboard\Config;
use Dashboard\Knowledge\PageService;
use Dashboard\Support\NotFoundException;
use Dashboard\Support\ValidationException;

/**
 * Vault and MCP bundle: patch, lookup, folders, archive, changed-since, and rank.
 */
final class VaultBotBundleTest extends PgsqlTestCase
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

    protected function tearDown(): void
    {
        Config::overlay('PUBLIC_BASE_URL', null);
        Config::overlay('APP_URL', null);
        parent::tearDown();
    }

    public function testPatchSavesARevisionAndLeavesThePreviousBody(): void
    {
        $page = $this->pages->create($this->user, [
            'title' => 'Daily log',
            'body'  => "# Keep\n\nstay\n\n## Log\n\nold line\n\n# Tail\n\nend",
        ], 'ai', 'patch_page', 'Grok');
        $id = (int) $page['id'];
        $original = (string) $page['body'];

        $appended = $this->pages->patch($this->user, [
            'page_id' => $id,
            'mode'    => 'append',
            'text'    => 'tail note',
        ], 'ai', 'patch_page', 'Grok');
        self::assertStringStartsWith($original, (string) $appended['body']);
        self::assertStringEndsWith('tail note', (string) $appended['body']);

        $section = $this->pages->patch($this->user, [
            'slug'    => 'daily-log',
            'mode'    => 'replace_section',
            'heading' => 'Log',
            'text'    => 'fresh',
        ], 'ai', 'patch_page', 'Grok');
        self::assertStringContainsString("## Log\n\nfresh\n", (string) $section['body']);
        self::assertStringContainsString("# Keep\n\nstay\n", (string) $section['body']);
        self::assertStringContainsString("# Tail\n\nend", (string) $section['body']);
        self::assertStringNotContainsString('old line', (string) $section['body']);

        $first = $this->db->fetchOne(
            'SELECT title, body FROM page_revisions WHERE page_id = ? AND version = 1',
            [$id]
        );
        self::assertSame($original, (string) $first['body']);
        self::assertSame('Daily log', (string) $first['title']);
        $latest = (int) $this->db->fetchScalar('SELECT MAX(version) FROM page_revisions WHERE page_id = ?', [$id]);
        self::assertSame(3, $latest);
        $current = $this->db->fetchOne(
            'SELECT body FROM page_revisions WHERE page_id = ? AND version = ?',
            [$id, $latest]
        );
        self::assertSame($section['body'], $current['body']);
        $diff = $this->pages->revisionDiff($this->user, $id, 1);
        self::assertGreaterThan(0, $diff['diff']['additions']);

        $audit = $this->db->fetchOne(
            "SELECT tool, action FROM audit_events WHERE user_id = ? AND action = 'page.update' ORDER BY id DESC LIMIT 1",
            [$this->user]
        );
        self::assertSame('patch_page', (string) $audit['tool']);
        self::assertSame('Grok', $section['last_editor']);
    }

    public function testLookupByTitleSlugAndFormerTitle(): void
    {
        $page = $this->pages->create($this->user, ['title' => 'Home', 'body' => 'start'], 'user', null, 'ada');
        $id = (int) $page['id'];
        $renamed = $this->pages->update($this->user, $id, ['title' => 'House'], 'user', 'write_page', 'ada');
        self::assertSame('House', $renamed['title']);
        self::assertSame('home', $renamed['slug']);
        self::assertContains('Home', $renamed['former_titles']);

        $byTitle = $this->pages->find($this->user, null, 'House', null);
        $bySlug = $this->pages->find($this->user, null, null, 'home');
        $byOld = $this->pages->find($this->user, null, 'Home', null);
        self::assertSame($id, (int) $byTitle['id']);
        self::assertSame($id, (int) $bySlug['id']);
        self::assertSame($id, (int) $byOld['id']);

        $found = $this->pages->list($this->user, 'Home');
        self::assertSame('House', $found[0]['title']);

        $old = $this->db->fetchOne(
            'SELECT title, body FROM page_revisions WHERE page_id = ? AND version = 1',
            [$id]
        );
        self::assertSame('Home', (string) $old['title']);
        self::assertSame('start', (string) $old['body']);

        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            ['bea', 'bea@x', 'h']
        );
        $other = (int) $this->db->lastInsertId();
        $this->pages->create($other, ['title' => 'House', 'body' => 'other']);
        self::assertSame($id, (int) $this->pages->find($this->user, null, 'House', null)['id']);
    }

    public function testUniqueLeafFolderAndPathErrors(): void
    {
        $david = $this->pages->createFolder($this->user, '01 David', null);
        $health = $this->pages->createFolder($this->user, 'Health', (int) $david['id']);
        $other = $this->pages->createFolder($this->user, '02 Other', null);
        self::assertSame((int) $health['id'], $this->pages->folderIdByPath($this->user, 'Health'));
        self::assertSame((int) $health['id'], $this->pages->folderIdByPath($this->user, '01 David/Health'));

        $page = $this->pages->create($this->user, [
            'title'  => 'Vitals',
            'body'   => 'pulse',
            'folder' => 'Health',
        ]);
        self::assertSame((int) $health['id'], $page['folder_id']);
        $found = $this->pages->list($this->user, 'pulse', 20, null, false);
        self::assertSame('Vitals', $found[0]['title']);
        $scoped = $this->pages->list($this->user, 'pulse', 20, $this->pages->folderIdByPath($this->user, 'Health'), true);
        self::assertSame('Vitals', $scoped[0]['title']);

        $this->pages->createFolder($this->user, 'Health', (int) $other['id']);
        try {
            $this->pages->folderIdByPath($this->user, 'Health');
            self::fail('two Health folders need a full path');
        } catch (ValidationException $e) {
            self::assertStringContainsString('01 David/Health', $e->getMessage());
            self::assertStringContainsString('02 Other/Health', $e->getMessage());
            self::assertStringContainsString('full path', $e->getMessage());
        }
        try {
            $this->pages->folderIdByPath($this->user, 'Missing');
            self::fail('a missing folder should name real paths');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('01 David/Health', $e->getMessage());
        }
    }

    public function testEmptyFolderContentsStillReturnTheFolder(): void
    {
        $folder = $this->pages->createFolder($this->user, 'Home IPTV', null);
        $contents = $this->pages->folderContents($this->user, (int) $folder['id']);
        self::assertSame('Home IPTV', $contents['folder']['name']);
        self::assertSame('Home IPTV', $contents['folder']['path']);
        self::assertSame([], $contents['pages']);
        self::assertSame([], $contents['folders']);

        $child = $this->pages->createFolder($this->user, 'Guides', (int) $folder['id']);
        $again = $this->pages->folderContents($this->user, (int) $folder['id']);
        self::assertSame([], $again['pages']);
        self::assertSame('Guides', $again['folders'][0]['name']);
        self::assertSame((int) $child['id'], $again['folders'][0]['id']);
    }

    public function testPageResultsIncludeTheDashboardUrl(): void
    {
        Config::overlay('PUBLIC_BASE_URL', 'https://dash.example.com');
        $page = $this->pages->create($this->user, ['title' => 'Linked', 'body' => 'here'], 'ai', 'write_page', 'Grok');
        $id = (int) $page['id'];
        self::assertSame('https://dash.example.com/brain?page=' . $id, $page['url']);
        $read = $this->pages->get($this->user, $id);
        self::assertSame($page['url'], $read['url']);
        $written = $this->pages->update($this->user, $id, ['body' => 'there'], 'ai', 'write_page', 'Grok');
        self::assertSame($page['url'], $written['url']);
        $listed = $this->pages->list($this->user, 'Linked');
        self::assertSame($page['url'], $listed[0]['url']);
    }

    public function testArchiveHidesFromSearchAndUnarchiveRestores(): void
    {
        $page = $this->pages->create($this->user, ['title' => 'Old note', 'body' => 'keep me']);
        $id = (int) $page['id'];
        $archived = $this->pages->archive($this->user, $id, 'ai', 'archive_page', 'Grok');
        self::assertTrue($archived['archived']);
        self::assertSame('keep me', $archived['body']);
        self::assertSame([], $this->pages->list($this->user, 'Old note'));
        $with = $this->pages->list($this->user, 'Old note', 20, null, false, true);
        self::assertSame('Old note', $with[0]['title']);
        self::assertTrue($with[0]['archived']);

        $restored = $this->pages->unarchive($this->user, $id, 'ai', 'unarchive_page', 'Grok');
        self::assertFalse($restored['archived']);
        self::assertSame('Old note', $this->pages->list($this->user, 'Old note')[0]['title']);

        $revisions = (int) $this->db->fetchScalar('SELECT COUNT(*) FROM page_revisions WHERE page_id = ?', [$id]);
        self::assertSame(1, $revisions);
        $audit = $this->db->fetchOne(
            "SELECT tool FROM audit_events WHERE user_id = ? AND action = 'page.unarchive'",
            [$this->user]
        );
        self::assertSame('unarchive_page', (string) $audit['tool']);

        $bin = $this->pages->createFolder($this->user, '99 Archive', null);
        $moved = $this->pages->create($this->user, ['title' => 'Filed away', 'body' => 'still live']);
        $this->pages->movePage($this->user, (int) $moved['id'], (int) $bin['id'], 'ai', 'move_page', 'Grok');
        $after = $this->pages->get($this->user, (int) $moved['id']);
        self::assertFalse($after['archived']);
        self::assertSame((int) $bin['id'], $after['folder_id']);
        self::assertSame('Filed away', $this->pages->list($this->user, 'Filed away')[0]['title']);
    }

    public function testChangedSinceIncludesTheLastEditor(): void
    {
        $page = $this->pages->create($this->user, ['title' => 'Today', 'body' => 'wrote'], 'ai', 'write_page', 'Grok');
        $rows = $this->pages->list($this->user, null, 20, null, false, false, '2020-01-01');
        self::assertSame('Today', $rows[0]['title']);
        self::assertSame('Grok', $rows[0]['last_editor']);
        self::assertNotSame('', $rows[0]['updated_at']);
        self::assertSame([], $this->pages->list($this->user, null, 20, null, false, false, '2999-01-01'));

        $edited = $this->pages->update($this->user, (int) $page['id'], ['body' => 'rewrote'], 'user', 'write_page', 'ada');
        self::assertSame('ada', $edited['last_editor']);
        $again = $this->pages->list($this->user, null, 20, null, false, false, '2020-01-01T00:00:00');
        self::assertSame('ada', $again[0]['last_editor']);
    }

    public function testSearchRanksTheExactTitleAboveBodyMentions(): void
    {
        $this->pages->create($this->user, [
            'title' => 'Notes',
            'body'  => 'home repairs and the iptv remote are in the drawer',
        ]);
        $this->pages->create($this->user, [
            'title' => 'Living room',
            'body'  => 'The Home IPTV box sits by the couch',
        ]);
        $this->pages->create($this->user, [
            'title' => 'Home IPTV',
            'body'  => 'channels',
        ]);
        $titles = array_column($this->pages->list($this->user, 'Home IPTV'), 'title');
        self::assertSame(['Home IPTV', 'Living room', 'Notes'], array_slice($titles, 0, 3));
    }

    public function testUnfiledPageSuggestsAFolder(): void
    {
        $health = $this->pages->createFolder($this->user, 'Health', null);
        $page = $this->pages->create($this->user, ['title' => 'Health log', 'body' => 'steps']);
        self::assertNull($page['folder_id']);
        self::assertSame((int) $health['id'], $page['suggested_folder']['id']);
        self::assertSame('Health', $page['suggested_folder']['path']);

        $filed = $this->pages->create($this->user, [
            'title'  => 'Health plan',
            'body'   => 'plan',
            'folder' => 'Health',
        ]);
        self::assertArrayNotHasKey('suggested_folder', $filed);
        self::assertSame((int) $health['id'], $filed['folder_id']);
    }
}
