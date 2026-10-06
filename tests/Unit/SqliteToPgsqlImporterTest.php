<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\SqliteToPgsqlImporter as I;
use PHPUnit\Framework\TestCase;

final class SqliteToPgsqlImporterTest extends TestCase
{
    /** @return array{type: string, max_length: ?int, nullable: bool} */
    private function col(string $type, ?int $max = null, bool $nullable = true): array
    {
        return ['type' => $type, 'max_length' => $max, 'nullable' => $nullable];
    }

    public function testNullStaysNull(): void
    {
        self::assertNull(I::convertValue(null, $this->col('bigint')));
        self::assertNull(I::convertValue(null, $this->col('timestamp')));
    }

    public function testIntegers(): void
    {
        $c = $this->col('smallint');
        self::assertSame(1, I::convertValue(1, $c));
        self::assertSame(0, I::convertValue('0', $c));
        self::assertSame(-12, I::convertValue(' -12 ', $c));
        self::assertSame(1, I::convertValue(true, $c));
        self::assertSame(3, I::convertValue(3.0, $c));
        self::assertNull(I::convertValue('', $c), "SQLite stored PHP false as ''");
    }

    public function testNonIntegerTextInIntegerColumnFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        I::convertValue('abc', $this->col('integer'));
    }

    public function testDoubles(): void
    {
        self::assertSame('12.5', I::convertValue(12.5, $this->col('double precision')));
        self::assertNull(I::convertValue('', $this->col('double precision')));
        $this->expectException(\InvalidArgumentException::class);
        I::convertValue('n/a', $this->col('double precision'));
    }

    public function testTimestamps(): void
    {
        $c = $this->col('timestamp');
        self::assertSame('2026-09-01 10:00:00', I::convertValue('2026-09-01 10:00:00', $c));
        self::assertSame('2026-09-01 10:00:00', I::convertValue('2026-09-01T10:00:00', $c));
        self::assertSame('2026-09-01 10:00:00', I::convertValue('2026-09-01T05:00:00-05:00', $c), 'offsets normalise to UTC');
        self::assertSame('2026-09-01 10:00:00', I::convertValue('2026-09-01T10:00:00Z', $c));
        self::assertSame('2001-09-09 01:46:40', I::convertValue(1000000000, $c), 'epoch seconds');
        self::assertNull(I::convertValue('', $c));
    }

    public function testInvalidTimestampFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        I::convertValue('not a date', $this->col('timestamp'));
    }

    public function testDates(): void
    {
        self::assertSame('2026-10-01', I::convertValue('2026-10-01', $this->col('date')));
        self::assertSame('2026-10-01', I::convertValue('2026-10-01 00:00:00', $this->col('date')));
    }

    public function testTextNulBytesStrippedAndLatin1Repaired(): void
    {
        self::assertSame('ab', I::convertValue("a\0b", $this->col('text')));
        self::assertSame('café', I::convertValue("caf\xE9", $this->col('text')));
        self::assertSame('42', I::convertValue(42, $this->col('text')));
    }

    public function testVarcharLengthEnforcedOrTruncated(): void
    {
        $c = $this->col('character varying', 5);
        self::assertSame('abcde', I::convertValue('abcde', $c));
        self::assertSame('abcde', I::convertValue('abcdefg', $c, truncateLongText: true));
        self::assertSame('ééééé', I::convertValue('éééééé', $c, truncateLongText: true), 'counts characters, not bytes');

        $this->expectException(\InvalidArgumentException::class);
        I::convertValue('abcdefg', $c);
    }

    public function testTopologicalOrderPutsParentsFirst(): void
    {
        $fk = static fn (string $ref): array => ['column' => $ref . '_id', 'ref_table' => $ref, 'ref_column' => 'id'];
        $order = I::topologicalOrder(
            ['bookmarks', 'users', 'bookmark_folders', 'feed_cache', 'feed_sources'],
            [
                'bookmarks'        => [$fk('users'), $fk('bookmark_folders')],
                'bookmark_folders' => [$fk('users')],
                'feed_cache'       => [$fk('feed_sources')],
                'feed_sources'     => [$fk('users')],
            ]
        );

        self::assertSame(['users', 'bookmark_folders', 'feed_sources', 'bookmarks', 'feed_cache'], $order);
    }

    public function testTopologicalOrderIgnoresSelfReferenceAndKeepsCyclesTerminating(): void
    {
        $order = I::topologicalOrder(
            ['a', 'b', 'c'],
            [
                'a' => [['column' => 'x', 'ref_table' => 'a', 'ref_column' => 'id']],
                'b' => [['column' => 'x', 'ref_table' => 'c', 'ref_column' => 'id']],
                'c' => [['column' => 'x', 'ref_table' => 'b', 'ref_column' => 'id']],
            ]
        );

        self::assertSame(['a', 'b', 'c'], $order);
    }
}
