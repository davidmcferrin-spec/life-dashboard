<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\Digest\DigestVersion;
use Dashboard\Support\ValidationException;
use PHPUnit\Framework\TestCase;

final class DigestVersionTest extends TestCase
{
    public function testVersionsSortNumericallyAndPreferParentheses(): void
    {
        $oneSeven = DigestVersion::parse('1.7');
        $oneTen = DigestVersion::parse('1.10');
        $two = DigestVersion::parse('2.0');
        self::assertNotNull($oneSeven);
        self::assertNotNull($oneTen);
        self::assertNotNull($two);
        self::assertLessThan($oneTen['version_sort'], $oneSeven['version_sort']);
        self::assertLessThan($two['version_sort'], $oneTen['version_sort']);
        self::assertSame($oneSeven['version_sort'], DigestVersion::parse('1.7.0')['version_sort']);

        self::assertSame('1.0.62', DigestVersion::parse('Grok Build 1.0.62')['version_text']);
        self::assertSame('0.71.2', DigestVersion::parse('Smarter notifications (0.71.2)')['version_text']);
        self::assertLessThan(
            DigestVersion::parse('0.71.2')['version_sort'],
            DigestVersion::parse('0.17.0')['version_sort']
        );
        self::assertSame('3.0', DigestVersion::parse('Grok Voice 3.0 is available')['version_text']);
        self::assertSame('4.7', DigestVersion::parse('Grok 4.7')['version_text']);
        self::assertSame('2026.10.08', DigestVersion::parse('v2026.10.08')['version_text']);
        self::assertNull(DigestVersion::parse('October'));
    }

    public function testUnknownVersionIsRejected(): void
    {
        try {
            DigestVersion::require('October', 'from_version');
            self::fail('A month name is not a version.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('from_version', $e->fields());
        }
    }
}
