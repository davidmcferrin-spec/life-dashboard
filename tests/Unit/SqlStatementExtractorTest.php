<?php

declare(strict_types=1);

namespace Dashboard\Tests\Unit;

use Dashboard\DB\SqlStatementExtractor;
use PHPUnit\Framework\TestCase;

final class SqlStatementExtractorTest extends TestCase
{
    /** @return list<array{sql: string, line: int, dynamic: bool}> */
    private function extract(string $body): array
    {
        return SqlStatementExtractor::extract("<?php\n" . $body);
    }

    private function norm(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }

    public function testSingleQuotedLiteral(): void
    {
        $r = $this->extract("\$this->db->fetchAll('SELECT * FROM users WHERE id = ?', [\$id]);");
        self::assertCount(1, $r);
        self::assertSame('SELECT * FROM users WHERE id = ?', $r[0]['sql']);
        self::assertFalse($r[0]['dynamic']);
        self::assertSame(2, $r[0]['line']);
    }

    public function testEscapedQuoteInSingleQuotedLiteral(): void
    {
        $r = $this->extract("\$db->execute('UPDATE t SET a = \\'x\\' WHERE id = ?', [1]);");
        self::assertSame("UPDATE t SET a = 'x' WHERE id = ?", $r[0]['sql']);
    }

    public function testDoubleQuotedWithInterpolationIsDynamic(): void
    {
        $r = $this->extract("\$db->fetchAll(\"SELECT * FROM t WHERE c IN ({\$list}) AND x = ?\", [1]);");
        self::assertSame('SELECT * FROM t WHERE c IN (NULL) AND x = ?', $r[0]['sql']);
        self::assertTrue($r[0]['dynamic']);
    }

    public function testConcatenationWithExpression(): void
    {
        $r = $this->extract("\$db->execute('UPDATE t SET ' . implode(', ', \$sets) . ' WHERE id = ?', \$p);");
        self::assertSame('UPDATE t SET NULL WHERE id = ?', $this->norm($r[0]['sql']));
        self::assertTrue($r[0]['dynamic']);
    }

    public function testVariableResolvedFromAssignments(): void
    {
        $code = <<<'PHP'
function listFor($db, $active) {
    $sql = 'SELECT * FROM t WHERE user_id = ?';
    if ($active) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY id';
    return $db->fetchAll($sql, [1]);
}
PHP;
        $r = $this->extract($code);
        self::assertCount(1, $r);
        self::assertSame('SELECT * FROM t WHERE user_id = ? AND is_active = 1 ORDER BY id', $r[0]['sql']);
        self::assertTrue($r[0]['dynamic'], 'conditional appends are a best guess');
    }

    public function testNonSqlAndNonDbCallsAreIgnored(): void
    {
        $r = $this->extract("\$http->query('https://example.com'); \$stmt->execute([1, 2]); \$x->fetchAll(\$unknown);");
        self::assertSame([], $r);
    }

    public function testIgnoreMarker(): void
    {
        $r = $this->extract("// sql-lint:ignore\n\$db->execute('SELECT sqlite_version()');");
        self::assertSame([], $r);
    }

    public function testHeredoc(): void
    {
        $code = "\$db->fetchOne(<<<SQL\nSELECT id FROM users WHERE name = ?\nSQL, ['x']);";
        $r = $this->extract($code);
        self::assertSame('SELECT id FROM users WHERE name = ?', $this->norm($r[0]['sql']));
    }

    public function testNullsafeOperator(): void
    {
        $r = $this->extract("\$db?->fetchScalar('SELECT COUNT(*) FROM users');");
        self::assertCount(1, $r);
    }

    public function testPlaceholdersConvertedOutsideQuotesOnly(): void
    {
        self::assertSame(
            "SELECT '?' AS q, \"a?\" FROM t WHERE a = \$1 AND b = \$2 AND c = 'it''s ?'",
            SqlStatementExtractor::toPgPlaceholders("SELECT '?' AS q, \"a?\" FROM t WHERE a = ? AND b = ? AND c = 'it''s ?'")
        );
    }
}
