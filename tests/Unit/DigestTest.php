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
        self::assertStringContainsString('id="digest-band"', $html);
        self::assertStringContainsString('id="ld-band-items"', $html);
        self::assertStringContainsString('id="ld-band-layouts"', $html);
        self::assertStringContainsString('ld-cols-capped', $html);
        self::assertStringContainsString('data-grid-cap', $html);
        self::assertStringContainsString('/assets/js/lifedash-grid.js', $html);
        self::assertDoesNotMatchRegularExpression('/max-width\s*:/', $html);
        $filtersAt = strpos($html, 'ld-digest-filters');
        $searchAt = strpos($html, 'id="digest-search"');
        $countAt = strpos($html, 'id="digest-count"');
        $feedAt = strpos($html, 'id="digest-feed"');
        self::assertNotFalse($filtersAt);
        self::assertNotFalse($searchAt);
        self::assertNotFalse($countAt);
        self::assertNotFalse($feedAt);
        self::assertLessThan($searchAt, $filtersAt);
        self::assertLessThan($countAt, $searchAt);
        self::assertLessThan($feedAt, $countAt);
        $dash = file_get_contents(VIEWS_DIR . '/dashboard.php');
        self::assertIsString($dash);
        self::assertStringContainsString('class="dash-main mx-auto py-6 space-y-6"', $dash);
        self::assertStringContainsString('ld-grid', $dash);
        self::assertStringContainsString('ld-cols-capped', $dash);
        self::assertStringContainsString('data-grid-cap', $dash);
        $css = file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/lifedash.css');
        self::assertIsString($css);
        self::assertStringContainsString('.ld-grid.ld-cols-capped', $css);
        self::assertStringContainsString('#digest-band', $css);
        self::assertStringContainsString('#digest-status:empty', $css);
        self::assertStringContainsString('[data-queue]', $css);
        self::assertStringContainsString('#sp-queue-modal', $css);
        $gridJs = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/lifedash-grid.js');
        self::assertIsString($gridJs);
        self::assertStringContainsString('const GRID_MAX = { 12: 1600, 18: 2400, 24: 3200 };', $gridJs);
        self::assertStringContainsString('.ld-grid[data-grid-cap]', $gridJs);
        self::assertStringContainsString("el.style.maxWidth = max + 'px'", $gridJs);
        self::assertStringContainsString('function layoutPositions', $gridJs);
        self::assertStringContainsString('function topRowBand', $gridJs);
        self::assertStringContainsString('return layoutPositions(canon, savedLayouts, cols);', $gridJs);
        self::assertStringContainsString("(100 / columns) + '%'", $gridJs);
        self::assertStringContainsString('const GRID_MARGIN = 6;', $gridJs);
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
        $html = file_get_contents(VIEWS_DIR . '/partials/app-shell-open.php');
        self::assertIsString($html);
        self::assertMatchesRegularExpression(
            "/\\['Vault', '\\/brain'\\].*\\['Grok Digest', '\\/digest'\\].*\\['Playlists', '\\/spotify'\\].*\\['Pantry', '\\/pantry'\\]/s",
            $html
        );
    }
}
