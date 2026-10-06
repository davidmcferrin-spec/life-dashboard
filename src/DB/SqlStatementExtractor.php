<?php

declare(strict_types=1);

namespace Dashboard\DB;

/**
 * SqlStatementExtractor — pulls SQL passed to Database query helpers out of PHP
 * source using the PHP tokenizer (no regex over code). Used by
 * scripts/sql-lint-pgsql.php and covered by tests/Unit/SqlStatementExtractorTest.php.
 *
 * Handles:
 *   - single / double quoted literals, heredoc/nowdoc
 *   - interpolation ("{$x}", "$x") and `.` concatenation with non-literals → NULL (dynamic)
 *   - a bare `$sql` argument, resolved from `$sql = …` / `$sql .= …` earlier in the same function
 *
 * Opt out a call with a comment containing `sql-lint:ignore` in the same statement.
 */
final class SqlStatementExtractor
{
    public const METHODS = ['fetchAll', 'fetchOne', 'fetchScalar', 'execute', 'query'];

    private const SQL_START = '/^\s*(SELECT|INSERT|UPDATE|DELETE|WITH)\b/i';

    /**
     * @return list<array{sql: string, line: int, dynamic: bool}>
     */
    public static function extract(string $code): array
    {
        $tokens = token_get_all($code);
        $count  = count($tokens);
        $out    = [];

        for ($i = 0; $i < $count; $i++) {
            if (!self::isToken($tokens[$i], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                continue;
            }

            $m = self::nextSignificant($tokens, $i);
            if ($m === null || !self::isToken($tokens[$m], [T_STRING]) || !in_array($tokens[$m][1], self::METHODS, true)) {
                continue;
            }

            $open = self::nextSignificant($tokens, $m);
            if ($open === null || $tokens[$open] !== '(') {
                continue;
            }

            if (self::hasIgnoreMarker($tokens, $m)) {
                continue;
            }

            $arg = self::firstArgument($tokens, $open);
            if ($arg === []) {
                continue;
            }

            $resolved = self::resolveOperands($tokens, $arg, $m);
            if ($resolved === null) {
                continue;
            }

            [$sql, $dynamic] = $resolved;
            if (!preg_match(self::SQL_START, $sql)) {
                continue;
            }

            $out[] = ['sql' => $sql, 'line' => (int) $tokens[$m][2], 'dynamic' => $dynamic];
        }

        return $out;
    }

    /**
     * Convert `?` placeholders to PostgreSQL `$1..$n`, skipping quoted text.
     */
    public static function toPgPlaceholders(string $sql): string
    {
        $out    = '';
        $n      = 0;
        $len    = strlen($sql);
        $quote  = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($quote !== null) {
                $out .= $ch;
                if ($ch === $quote) {
                    // Doubled quote is an escape inside SQL strings/identifiers.
                    if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                        $out .= $sql[++$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $quote = $ch;
                $out  .= $ch;
                continue;
            }

            if ($ch === '?') {
                $out .= '$' . (++$n);
                continue;
            }

            $out .= $ch;
        }

        return $out;
    }

    // ---------------------------------------------------------------------

    /**
     * @param list<array|string> $tokens
     * @return list<int> token indexes of the first call argument
     */
    private static function firstArgument(array $tokens, int $open): array
    {
        $depth = 0;
        $idx   = [];

        for ($i = $open + 1, $count = count($tokens); $i < $count; $i++) {
            $t = $tokens[$i];
            if (in_array($t, ['(', '[', '{'], true) || self::isToken($t, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } elseif ($t === ',' && $depth === 0) {
                break;
            }
            $idx[] = $i;
        }

        return $idx;
    }

    /**
     * Split an expression on top-level `.` and resolve each operand.
     *
     * @param list<array|string> $tokens
     * @param list<int>          $idx
     * @return array{0: string, 1: bool}|null
     */
    private static function resolveOperands(array $tokens, array $idx, int $callIndex): ?array
    {
        $significant = array_values(array_filter($idx, static fn (int $i): bool => !self::isTrivia($tokens[$i])));

        // Bare variable: resolve from assignments earlier in the same function.
        if (count($significant) === 1 && self::isToken($tokens[$significant[0]], [T_VARIABLE])) {
            return self::resolveVariable($tokens, $tokens[$significant[0]][1], $callIndex);
        }

        $operands = [[]];
        $depth    = 0;
        foreach ($significant as $i) {
            $t = $tokens[$i];
            if (in_array($t, ['(', '['], true) || self::isToken($t, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                $depth--;
            }
            if ($t === '.' && $depth === 0) {
                $operands[] = [];
                continue;
            }
            $operands[count($operands) - 1][] = $i;
        }

        $sql     = '';
        $dynamic = false;
        $anyLiteral = false;

        foreach ($operands as $op) {
            $literal = self::literalValue($tokens, $op);
            if ($literal === null) {
                $sql    .= ' NULL ';
                $dynamic = true;
                continue;
            }
            [$text, $interpolated] = $literal;
            $sql       .= $text;
            $dynamic    = $dynamic || $interpolated;
            $anyLiteral = true;
        }

        return $anyLiteral ? [$sql, $dynamic] : null;
    }

    /**
     * @param list<array|string> $tokens
     * @return array{0: string, 1: bool}|null
     */
    private static function resolveVariable(array $tokens, string $var, int $callIndex): ?array
    {
        // Walk back to the enclosing function keyword.
        $start = 0;
        for ($i = $callIndex; $i >= 0; $i--) {
            if (self::isToken($tokens[$i], [T_FUNCTION, T_FN])) {
                $start = $i;
                break;
            }
        }

        $parts   = [];
        $dynamic = false;

        for ($i = $start; $i < $callIndex; $i++) {
            if (!self::isToken($tokens[$i], [T_VARIABLE]) || $tokens[$i][1] !== $var) {
                continue;
            }
            $op = self::nextSignificant($tokens, $i);
            if ($op === null) {
                continue;
            }
            $isAssign = $tokens[$op] === '=';
            $isAppend = self::isToken($tokens[$op], [T_CONCAT_EQUAL]);
            if (!$isAssign && !$isAppend) {
                continue;
            }

            // Expression runs to the terminating ';' at depth 0.
            $expr  = [];
            $depth = 0;
            for ($j = $op + 1, $count = count($tokens); $j < $count; $j++) {
                $t = $tokens[$j];
                if (in_array($t, ['(', '[', '{'], true) || self::isToken($t, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                    $depth++;
                } elseif (in_array($t, [')', ']', '}'], true)) {
                    $depth--;
                } elseif ($t === ';' && $depth === 0) {
                    break;
                }
                $expr[] = $j;
            }

            $resolved = self::resolveOperands($tokens, $expr, $op);
            if ($resolved === null) {
                if ($isAssign) {
                    $parts = [];
                }
                $dynamic = true;
                continue;
            }

            if ($isAssign) {
                $parts = [];
            } else {
                // Appends are often conditional; the combined SQL is only a best guess.
                $dynamic = true;
            }
            $parts[] = $resolved[0];
            $dynamic = $dynamic || $resolved[1];
        }

        if ($parts === []) {
            return null;
        }

        return [implode('', $parts), $dynamic];
    }

    /**
     * Literal text of a single operand, or null if it is not a string literal.
     *
     * @param list<array|string> $tokens
     * @param list<int>          $op
     * @return array{0: string, 1: bool}|null  [text, hadInterpolation]
     */
    private static function literalValue(array $tokens, array $op): ?array
    {
        if ($op === []) {
            return null;
        }

        $first = $tokens[$op[0]];

        if (count($op) === 1 && self::isToken($first, [T_CONSTANT_ENCAPSED_STRING])) {
            return [self::decodeConstantString($first[1]), false];
        }

        $isDouble  = $first === '"';
        $isHeredoc = self::isToken($first, [T_START_HEREDOC]);
        if (!$isDouble && !$isHeredoc) {
            return null;
        }

        $nowdoc = $isHeredoc && str_contains($first[1], "'");
        $text   = '';
        $interp = false;
        $depth  = 0;

        for ($k = 1, $n = count($op); $k < $n; $k++) {
            $t = $tokens[$op[$k]];

            if ($depth > 0) {
                if (self::isToken($t, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) || $t === '{') {
                    $depth++;
                } elseif ($t === '}') {
                    $depth--;
                }
                continue;
            }

            if ($t === '"' || self::isToken($t, [T_END_HEREDOC])) {
                break;
            }
            if (self::isToken($t, [T_ENCAPSED_AND_WHITESPACE])) {
                $text .= $nowdoc ? $t[1] : self::decodeDoubleQuoted($t[1]);
                continue;
            }
            if (self::isToken($t, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $text  .= 'NULL';
                $interp = true;
                $depth  = 1;
                continue;
            }
            if (self::isToken($t, [T_VARIABLE])) {
                $text  .= 'NULL';
                $interp = true;
                // Skip simple "$a[0]" / "$a->b" tails.
                while ($k + 1 < $n) {
                    $nx = $tokens[$op[$k + 1]];
                    if ($nx === '[' || $nx === ']' || self::isToken($nx, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_NUM_STRING, T_STRING])) {
                        $k++;
                        continue;
                    }
                    break;
                }
                continue;
            }
        }

        return [$text, $interp];
    }

    private static function decodeConstantString(string $raw): string
    {
        $q    = $raw[0];
        $body = substr($raw, 1, -1);

        if ($q === "'") {
            return str_replace(["\\'", '\\\\'], ["'", '\\'], $body);
        }

        return self::decodeDoubleQuoted($body);
    }

    private static function decodeDoubleQuoted(string $body): string
    {
        return strtr($body, ['\\n' => "\n", '\\t' => "\t", '\\r' => "\r", '\\"' => '"', '\\$' => '$', '\\\\' => '\\']);
    }

    /**
     * @param list<array|string> $tokens
     */
    private static function hasIgnoreMarker(array $tokens, int $from): bool
    {
        // Look back to the start of the statement …
        for ($i = $from; $i >= 0; $i--) {
            $t = $tokens[$i];
            if ($t === ';' || $t === '{' || $t === '}') {
                break;
            }
            if (self::isToken($t, [T_COMMENT, T_DOC_COMMENT]) && str_contains($t[1], 'sql-lint:ignore')) {
                return true;
            }
        }
        // … and forward to its end.
        for ($i = $from, $count = count($tokens); $i < $count; $i++) {
            $t = $tokens[$i];
            if ($t === ';') {
                break;
            }
            if (self::isToken($t, [T_COMMENT, T_DOC_COMMENT]) && str_contains($t[1], 'sql-lint:ignore')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array|string> $tokens
     */
    private static function nextSignificant(array $tokens, int $i): ?int
    {
        for ($j = $i + 1, $count = count($tokens); $j < $count; $j++) {
            if (!self::isTrivia($tokens[$j])) {
                return $j;
            }
        }

        return null;
    }

    private static function isTrivia(array|string $t): bool
    {
        return self::isToken($t, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]);
    }

    /**
     * @param list<int> $ids
     */
    private static function isToken(array|string $t, array $ids): bool
    {
        return is_array($t) && in_array($t[0], $ids, true);
    }
}
