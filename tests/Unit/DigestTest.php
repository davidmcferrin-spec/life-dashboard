<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Digest\DigestBrief;
use Dashboard\Digest\DigestCatalog;
use Dashboard\Digest\DigestUrl;
use Dashboard\Settings\ServiceControl;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class DigestTest extends TestCase
{
    public function testSeedUsesTheResolvedDocsPage(): void
    {
        $urls = array_column(DigestCatalog::seeds(), 'url');
        self::assertContains('https://docs.x.ai/developers/release-notes', $urls);
        self::assertNotContains('https://x.ai/docs/release-notes', $urls);
        self::assertCount(8, $urls);
        self::assertSame('Cursor IDE', DigestCatalog::PRODUCTS['cursor_ide']);
    }

    public function testDigestScopeIsSeparateFromReadShorthandGaps(): void
    {
        self::assertSame('digest:read', Scope::forPath('/api/v1/digest/items', false));
        self::assertSame('digest:write', Scope::forPath('/api/v1/digest/check', true));
        self::assertTrue(Scope::allows(['digest:write'], 'digest:read'));
        self::assertFalse(Scope::allows(['digest:read'], 'digest:write'));
        self::assertContains('digest:read', Scope::normalize(['read']));
    }

    public function testPrivateUrlsAreRejected(): void
    {
        foreach (['http://notes.example.com/a', 'https://127.0.0.1/a', 'https://localhost/a', 'https://user:pass@notes.example.com/a'] as $url) {
            try {
                DigestUrl::assert($url);
                self::fail($url . ' should be rejected');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('url', $e->fields());
            }
        }
    }

    public function testCheckNowQueuesOnTheServiceSpool(): void
    {
        $spool = sys_get_temp_dir() . '/ld-digest-' . bin2hex(random_bytes(4));
        mkdir($spool . '/incoming', 0777, true);
        try {
            $queued = (new ServiceControl($spool))->request('digest-check', ['user_id' => '4']);
            $body = json_decode((string) file_get_contents($spool . '/incoming/' . $queued['id'] . '.json'), true);
            self::assertSame('digest-check', $body['action']);
            self::assertSame('4', $body['user_id']);
        } finally {
            array_map('unlink', glob($spool . '/incoming/*') ?: []);
            @rmdir($spool . '/incoming');
            @rmdir($spool);
        }
    }

    public function testExcerptKeepsTwoShortSentences(): void
    {
        $note = 'Sample Ensemble kept the bridge quiet. The lights stayed low. A third sentence belongs in the full note and not in the card line that people scan first while deciding whether to open it.';
        self::assertSame(
            'Sample Ensemble kept the bridge quiet. The lights stayed low.',
            DigestBrief::excerpt($note)
        );
        self::assertSame('', DigestBrief::excerpt('   '));
    }

    public function testDigestPageUsesTheDashboardMain(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/digest.php');
        self::assertIsString($html);
        self::assertStringContainsString('class="dash-main mx-auto py-6 space-y-6"', $html);
        self::assertStringContainsString('One feed of Cursor, Grok Bot, Grok, and Grok Build release notes.', $html);
        self::assertStringContainsString('id="digest-group"', $html);
        self::assertStringContainsString('id="digest-more"', $html);
        self::assertDoesNotMatchRegularExpression('/max-width\s*:/', $html);
    }

    public function testDashboardLinksDigestWithTheOtherModules(): void
    {
        $html = file_get_contents(VIEWS_DIR . '/dashboard.php');
        self::assertIsString($html);
        self::assertMatchesRegularExpression(
            '/<div class="nav-meta flex items-center gap-3 flex-wrap justify-end">'
            . '.*<a href="\/brain" class="nav-link">Vault<\/a>\s*'
            . '<a href="\/spotify" class="nav-link">Playlists<\/a>\s*'
            . '<a href="\/pantry" class="nav-link">Pantry<\/a>\s*'
            . '<a href="\/digest" class="nav-link">Grok Digest<\/a>/s',
            $html
        );
    }
}
