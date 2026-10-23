<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Digest\DigestBrief;
use Dashboard\Digest\DigestCatalog;
use Dashboard\Digest\DigestDates;
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
        self::assertStringContainsString('ld-grid', $html);
        self::assertStringContainsString('ld-cols-capped', $html);
        self::assertDoesNotMatchRegularExpression('/max-width\s*:/', $html);
        $dash = file_get_contents(VIEWS_DIR . '/dashboard.php');
        self::assertIsString($dash);
        self::assertStringContainsString('class="dash-main mx-auto py-6 space-y-6"', $dash);
        self::assertStringContainsString('ld-grid', $dash);
        self::assertStringContainsString('ld-cols-capped', $dash);
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/lifedash.css');
        self::assertIsString($css);
        self::assertStringContainsString('.ld-grid.ld-cols-capped', $css);
        self::assertStringContainsString('max-width: 1600px', $css);
        self::assertStringContainsString('max-width: 2400px', $css);
        self::assertStringContainsString('max-width: 3200px', $css);
        self::assertStringContainsString('.ld-digest-date.is-found', $css);
        $js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/digest.js');
        self::assertIsString($js);
        self::assertStringContainsString("item.date_source === 'found'", $js);
        self::assertStringContainsString('is-found', $js);
        self::assertStringContainsString('Found ', $js);
    }

    public function testPeelPullsATrailingDateAndLeavesADateInsideTheSentence(): void
    {
        $peeled = DigestDates::peel('We are releasing the model Grok-1. Mar 17, 2024');
        self::assertSame('We are releasing the model Grok-1.', $peeled['text']);
        self::assertSame('2024-03-17', $peeled['published_on']);
        self::assertSame('Mar 17, 2024', $peeled['token']);

        $crumb = DigestDates::peel('Product Oct 12, 2026');
        self::assertSame('', $crumb['text']);
        self::assertSame('2026-10-12', $crumb['published_on']);

        $kept = 'Requests continue on October 2, 2026. The line stays.';
        $inside = DigestDates::peel($kept);
        self::assertSame($kept, $inside['text']);
        self::assertNull($inside['published_on']);

        $fixed = DigestDates::repair(
            'Open Release of Grok-1',
            null,
            'We are releasing the model Grok-1. Mar 17, 2024',
            'We are releasing the model Grok-1. March 17, 2024',
            'ollama'
        );
        self::assertNotNull($fixed);
        self::assertSame('We are releasing the model Grok-1.', $fixed['summary']);
        self::assertSame('2024-03-17', $fixed['published_on']);
        self::assertSame('excerpt', $fixed['brief_kind']);
        self::assertSame('We are releasing the model Grok-1.', $fixed['brief']);
        self::assertSame('02cffed9d1a3f454f1234d663139f42ec7bdf13a2fa9e1cdef58794b28b82aba', $fixed['content_hash']);
        self::assertNull(DigestDates::repair(
            'Open Release of Grok-1',
            '2024-03-17',
            'We are releasing the model Grok-1.',
            'We are releasing the model Grok-1.',
            'excerpt'
        ));
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
