<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Api\V1\Scope;
use Dashboard\Knowledge\Redline;
use PHPUnit\Framework\TestCase;

final class RedlineTest extends TestCase
{
    public function testInsertKeepsTheUnchangedWord(): void
    {
        $diff = Redline::compare('alpha', 'alpha beta');

        self::assertSame('word', $diff['grain']);
        self::assertSame(1, $diff['additions']);
        self::assertSame(0, $diff['deletions']);
        self::assertSame('alpha<ins> beta</ins>', $diff['html']);
        self::assertSame('1 addition, 0 deletions', $diff['summary']);
    }

    public function testDeleteMarksOnlyTheRemovedWord(): void
    {
        $diff = Redline::compare('alpha beta', 'alpha');

        self::assertSame(0, $diff['additions']);
        self::assertSame(1, $diff['deletions']);
        self::assertSame('alpha<del> beta</del>', $diff['html']);
        self::assertSame('0 additions, 1 deletion', $diff['summary']);
    }

    public function testReplaceIsWordLevelInsideTheLine(): void
    {
        $diff = Redline::compare("hello world\nsame", "hello there\nsame");

        self::assertSame('word', $diff['grain']);
        self::assertSame(1, $diff['additions']);
        self::assertSame(1, $diff['deletions']);
        self::assertSame("hello <del>world</del><ins>there</ins>\nsame", $diff['html']);
        self::assertSame('1 addition, 1 deletion', $diff['summary']);
    }

    public function testUnicodeStaysOneWord(): void
    {
        $cafe = Redline::compare('café au lait', 'cafe au lait');
        self::assertSame('<del>café</del><ins>cafe</ins> au lait', $cafe['html']);
        self::assertSame(1, $cafe['additions']);
        self::assertSame(1, $cafe['deletions']);

        $cjk = Redline::compare('see 你好 now', 'see 你好 later');
        self::assertSame('see 你好 <del>now</del><ins>later</ins>', $cjk['html']);
        self::assertSame(1, $cjk['additions']);
        self::assertSame(1, $cjk['deletions']);
    }

    public function testHtmlIsEscapedInChangesAndInUnchangedText(): void
    {
        $changed = Redline::compare('note', '<img src=x onerror="alert(1)">');
        self::assertStringContainsString('&lt;img src=x onerror=&quot;alert(1)&quot;&gt;', $changed['html']);
        self::assertStringNotContainsString('<img', $changed['html']);
        self::assertStringNotContainsString('<script', $changed['html']);
        $this->assertOnlyInsAndDel($changed['html']);

        $same = Redline::compare('a & b', 'a & b');
        self::assertSame('a &amp; b', $same['html']);
        self::assertSame(0, $same['additions']);
        self::assertSame(0, $same['deletions']);
        self::assertSame('0 additions, 0 deletions', $same['summary']);
    }

    public function testIdenticalAndEmptyAndCrLf(): void
    {
        $same = Redline::compare("line\r\nnext", "line\nnext");
        self::assertSame("line\nnext", $same['html']);
        self::assertSame(0, $same['additions']);
        self::assertSame(0, $same['deletions']);

        $empty = Redline::compare('', '');
        self::assertSame('', $empty['html']);
        self::assertSame(0, $empty['additions']);
        self::assertSame(0, $empty['deletions']);

        $fromEmpty = Redline::compare('', 'added');
        self::assertSame('<ins>added</ins>', $fromEmpty['html']);
        self::assertSame(1, $fromEmpty['additions']);
        self::assertSame(0, $fromEmpty['deletions']);
    }

    public function testLargePageFallsBackToWholeLines(): void
    {
        $prefix = str_repeat('alpha ', 5000);
        $before = $prefix . 'old';
        $after = $prefix . 'new';
        self::assertGreaterThan(Redline::LARGE_CHARS, strlen($before) + strlen($after));

        $diff = Redline::compare($before, $after);

        self::assertSame('line', $diff['grain']);
        self::assertSame(1, $diff['additions']);
        self::assertSame(1, $diff['deletions']);
        self::assertStringStartsWith('<del>', $diff['html']);
        self::assertStringContainsString('<ins>', $diff['html']);
        self::assertStringContainsString('old', $diff['html']);
        self::assertStringContainsString('new', $diff['html']);
        $this->assertOnlyInsAndDel($diff['html']);
    }

    public function testRevisionEndpointRequiresPagesRead(): void
    {
        $path = '/api/v1/pages/4/revisions/2';
        $required = Scope::forPath($path, false);

        self::assertSame('pages:read', $required);
        self::assertSame(Redline::requiredScope(), $required);
        self::assertFalse(Scope::sessionOnly($path));
        self::assertTrue(Scope::allows(['pages:read'], $required));
        self::assertTrue(Scope::allows(['pages:write'], $required));
        self::assertTrue(Scope::allows(['*'], $required));
        self::assertFalse(Scope::allows(['money:read'], $required));
        self::assertFalse(Scope::allows(['pages:read'], Scope::forPath($path, true)));
        self::assertFalse(Scope::allows([], $required));
    }

    private function assertOnlyInsAndDel(string $html): void
    {
        $plain = preg_replace('#</?(?:ins|del)>#', '', $html);
        self::assertIsString($plain);
        self::assertDoesNotMatchRegularExpression('/[<>]/', $plain);
    }
}
