<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Config;
use Dashboard\Knowledge\FolderSuggest;
use Dashboard\Knowledge\PagePatch;
use Dashboard\Knowledge\PageQuery;
use Dashboard\Knowledge\PageRank;
use Dashboard\Knowledge\PageUrl;
use Dashboard\Mcp\AgentPrompt;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class VaultMcpBundleTest extends TestCase
{
    protected function tearDown(): void
    {
        Config::overlay('PUBLIC_BASE_URL', null);
        Config::overlay('APP_URL', null);
    }

    public function testPatchAppendsPrependsAndEditsOneSection(): void
    {
        $body = "# Keep\n\nstay\n\n## Log\n\nold line\n\n# Tail\n\nend";
        $appended = PagePatch::apply($body, 'append', "next\nday");
        self::assertStringStartsWith($body, $appended);
        self::assertStringEndsWith("next\nday", $appended);

        $prepended = PagePatch::apply('body', 'prepend', 'top');
        self::assertSame("top\n\nbody", $prepended);

        $replaced = PagePatch::apply($body, 'replace_section', 'fresh', 'Log');
        self::assertStringStartsWith("# Keep\n\nstay\n\n", $replaced);
        self::assertStringContainsString("## Log\n\nfresh\n\n# Tail\n\nend", $replaced);
        self::assertStringNotContainsString('old line', $replaced);

        $inserted = PagePatch::apply($body, 'insert_section', 'fresh', '## Log', 'end');
        self::assertStringContainsString("old line\n\nfresh\n\n# Tail", $inserted);
        self::assertStringStartsWith("# Keep\n\nstay\n\n", $inserted);
        self::assertStringEndsWith("# Tail\n\nend", $inserted);

        $started = PagePatch::apply($body, 'insert_section', 'fresh', 'Log', 'start');
        self::assertStringContainsString("## Log\n\nfresh\n\nold line\n\n# Tail", $started);

        $fenced = "# Real\n\ntext\n\n```\n## Fake\n```\n\n# Next\n\nstay";
        try {
            PagePatch::apply($fenced, 'replace_section', 'nope', 'Fake');
            self::fail('a heading inside a fence is not a section');
        } catch (ValidationException $e) {
            self::assertStringContainsString('was not found', $e->getMessage());
            self::assertStringContainsString('Real', $e->getMessage());
        }
        $next = PagePatch::apply($fenced, 'replace_section', 'yes', 'Next');
        self::assertStringContainsString("```\n## Fake\n```", $next);
        self::assertStringContainsString('text', $next);
        self::assertStringContainsString("# Next\n\nyes", $next);
        self::assertStringNotContainsString('stay', $next);

        $twice = "# One\n\na\n\n# One\n\nb";
        try {
            PagePatch::apply($twice, 'replace_section', 'c', 'One');
            self::fail('two headings with the same text are ambiguous');
        } catch (ValidationException $e) {
            self::assertStringContainsString('more than once', $e->getMessage());
        }
    }

    public function testRankPutsTitleAndPhraseAboveBodyMentions(): void
    {
        $title = PageRank::score('Home IPTV', 'Home IPTV', 'channels');
        $former = PageRank::score('Home IPTV', 'TV', 'channels', ['Home IPTV']);
        $titlePhrase = PageRank::score('Home IPTV', 'Home IPTV setup', 'channels');
        $bodyPhrase = PageRank::score('Home IPTV', 'Living room', 'The Home IPTV box sits here');
        $bodyWords = PageRank::score('Home IPTV', 'Notes', 'home repairs and the iptv remote');
        $semantic = PageRank::score('Home IPTV', 'Kitchen', 'soup', [], false);
        self::assertGreaterThan($former, $title);
        self::assertGreaterThan($titlePhrase, $former);
        self::assertGreaterThan($bodyPhrase, $titlePhrase);
        self::assertGreaterThan($bodyWords, $bodyPhrase);
        self::assertGreaterThan($semantic, $bodyWords);
    }

    public function testFolderSuggestUsesAUniqueTitleMatch(): void
    {
        $folders = [
            ['id' => 15, 'name' => 'Health', 'path' => '01 David/Health'],
            ['id' => 4, 'name' => 'Recipes', 'path' => 'Recipes'],
        ];
        $hit = FolderSuggest::suggest('Health log', $folders);
        self::assertNotNull($hit);
        self::assertSame(15, $hit['id']);
        self::assertSame('01 David/Health', $hit['path']);
        self::assertNull(FolderSuggest::suggest('Healthcare notes', $folders));
        self::assertNull(FolderSuggest::suggest('Health recipes', $folders));
        self::assertNull(FolderSuggest::suggest('Nothing here', $folders));
    }

    public function testPageUrlUsesThePublicOrigin(): void
    {
        Config::overlay('APP_URL', 'https://dash.lan');
        Config::overlay('PUBLIC_BASE_URL', null);
        self::assertSame('https://dash.lan/brain?page=12', PageUrl::forId(12));
        Config::overlay('PUBLIC_BASE_URL', 'https://dash.example.com');
        self::assertSame('https://dash.example.com/brain?page=12', PageUrl::forId(12));
    }

    public function testChangedSinceAcceptsADate(): void
    {
        self::assertSame('2026-11-01 00:00:00', PageQuery::since('2026-11-01'));
        self::assertSame('2026-11-01 15:04:05', PageQuery::since('2026-11-01T15:04:05'));
        try {
            PageQuery::since('yesterday');
            self::fail('a changed-since filter needs a date');
        } catch (ValidationException $e) {
            self::assertStringContainsString('YYYY-MM-DD', $e->getMessage());
        }
    }

    public function testNewPageRoutesStayOnThePagesScope(): void
    {
        self::assertSame('pages:read', Scope::forPath('/api/v1/pages/lookup', false));
        self::assertSame('pages:read', Scope::forPath('/api/v1/pages/folders/contents', false));
        self::assertSame('pages:write', Scope::forPath('/api/v1/pages/patch', true));
        self::assertSame('pages:write', Scope::forPath('/api/v1/pages/archive', true));
        self::assertSame('pages:write', Scope::forPath('/api/v1/pages/unarchive', true));
    }

    public function testPromptNamesTheVaultTools(): void
    {
        $prompt = AgentPrompt::text();
        foreach ([
            'read_page',
            'patch_page',
            'archive_page',
            'unarchive_page',
            'list_folder_contents',
            'list_changed_pages',
            'include_archived',
            'updated_since',
            'last_editor',
            'suggested_folder',
            '99 Archive',
        ] as $name) {
            self::assertStringContainsString($name, $prompt);
        }
    }
}
