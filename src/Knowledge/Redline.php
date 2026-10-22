<?php

declare(strict_types=1);

namespace Dashboard\Knowledge;

/**
 * Word-level redline of vault source text. The HTML is escaped, then wrapped
 * in ins and del. A very large page is compared line by line so the request
 * stays bounded.
 */
final class Redline
{
    public const LARGE_CHARS = 60000;

    public const LARGE_LINES = 800;

    public static function requiredScope(): string
    {
        return 'pages:read';
    }

    /**
     * @return array{html: string, additions: int, deletions: int, grain: string, summary: string}
     */
    public static function compare(string $before, string $after): array
    {
        $before = str_replace(["\r\n", "\r"], "\n", $before);
        $after = str_replace(["\r\n", "\r"], "\n", $after);
        $beforeLines = self::lines($before);
        $afterLines = self::lines($after);
        $large = strlen($before) + strlen($after) > self::LARGE_CHARS
            || count($beforeLines) > self::LARGE_LINES
            || count($afterLines) > self::LARGE_LINES;
        $ops = $large
            ? self::diffTokens($beforeLines, $afterLines)
            : self::refineLines(self::diffTokens($beforeLines, $afterLines));
        return self::render($ops, $large ? 'line' : 'word');
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{op: string, text: string}>
     */
    private static function diffTokens(array $before, array $after): array
    {
        $n = count($before);
        $m = count($after);
        if ($n === 0 && $m === 0) {
            return [];
        }
        if ($n * $m > 250000) {
            return self::replaceAll($before, $after);
        }
        $dp = [];
        for ($i = $n; $i >= 0; $i--) {
            $dp[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                if ($before[$i] === $after[$j]) {
                    $dp[$i][$j] = $dp[$i + 1][$j + 1] + 1;
                } else {
                    $dp[$i][$j] = $dp[$i + 1][$j] >= $dp[$i][$j + 1] ? $dp[$i + 1][$j] : $dp[$i][$j + 1];
                }
            }
        }
        $ops = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($before[$i] === $after[$j]) {
                $ops[] = ['op' => 'eq', 'text' => $before[$i]];
                $i++;
                $j++;
            } elseif ($dp[$i + 1][$j] >= $dp[$i][$j + 1]) {
                $ops[] = ['op' => 'del', 'text' => $before[$i]];
                $i++;
            } else {
                $ops[] = ['op' => 'ins', 'text' => $after[$j]];
                $j++;
            }
        }
        while ($i < $n) {
            $ops[] = ['op' => 'del', 'text' => $before[$i]];
            $i++;
        }
        while ($j < $m) {
            $ops[] = ['op' => 'ins', 'text' => $after[$j]];
            $j++;
        }
        return $ops;
    }

    /**
     * Pair replaced lines and diff their words. Unpaired lines stay whole.
     *
     * @param list<array{op: string, text: string}> $lineOps
     * @return list<array{op: string, text: string}>
     */
    private static function refineLines(array $lineOps): array
    {
        $out = [];
        $count = count($lineOps);
        $i = 0;
        while ($i < $count) {
            if ($lineOps[$i]['op'] === 'eq') {
                $out[] = $lineOps[$i];
                $i++;
                continue;
            }
            $dels = [];
            $inss = [];
            while ($i < $count && $lineOps[$i]['op'] !== 'eq') {
                if ($lineOps[$i]['op'] === 'del') {
                    $dels[] = $lineOps[$i]['text'];
                } else {
                    $inss[] = $lineOps[$i]['text'];
                }
                $i++;
            }
            $pairs = min(count($dels), count($inss));
            for ($p = 0; $p < $pairs; $p++) {
                $words = self::words($dels[$p]);
                $next = self::words($inss[$p]);
                if (count($words) > 200 || count($next) > 200) {
                    $out[] = ['op' => 'del', 'text' => $dels[$p]];
                    $out[] = ['op' => 'ins', 'text' => $inss[$p]];
                    continue;
                }
                foreach (self::diffTokens($words, $next) as $op) {
                    $out[] = $op;
                }
            }
            for ($p = $pairs; $p < count($dels); $p++) {
                $out[] = ['op' => 'del', 'text' => $dels[$p]];
            }
            for ($p = $pairs; $p < count($inss); $p++) {
                $out[] = ['op' => 'ins', 'text' => $inss[$p]];
            }
        }
        return $out;
    }

    /**
     * @param list<string> $before
     * @param list<string> $after
     * @return list<array{op: string, text: string}>
     */
    private static function replaceAll(array $before, array $after): array
    {
        $ops = [];
        foreach ($before as $text) {
            $ops[] = ['op' => 'del', 'text' => $text];
        }
        foreach ($after as $text) {
            $ops[] = ['op' => 'ins', 'text' => $text];
        }
        return $ops;
    }

    /**
     * @return list<string>
     */
    private static function lines(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $parts = explode("\n", $text);
        $last = count($parts) - 1;
        $out = [];
        foreach ($parts as $i => $part) {
            $out[] = $i === $last ? $part : $part . "\n";
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private static function words(string $line): array
    {
        $parts = preg_split('/(\s+)/u', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return [$line];
        }
        $out = [];
        foreach ($parts as $part) {
            if ($part !== '') {
                $out[] = $part;
            }
        }
        return $out;
    }

    /**
     * @param list<array{op: string, text: string}> $ops
     * @return array{html: string, additions: int, deletions: int, grain: string, summary: string}
     */
    private static function render(array $ops, string $grain): array
    {
        $html = '';
        $additions = 0;
        $deletions = 0;
        $bufOp = '';
        $buf = '';
        $flush = static function () use (&$html, &$bufOp, &$buf, &$additions, &$deletions, $grain): void {
            if ($buf === '') {
                return;
            }
            $escaped = htmlspecialchars($buf, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($bufOp === 'del') {
                $html .= '<del>' . $escaped . '</del>';
                $deletions += self::units($buf, $grain);
            } elseif ($bufOp === 'ins') {
                $html .= '<ins>' . $escaped . '</ins>';
                $additions += self::units($buf, $grain);
            } else {
                $html .= $escaped;
            }
            $buf = '';
        };
        foreach ($ops as $op) {
            if ($op['op'] !== $bufOp) {
                $flush();
                $bufOp = $op['op'];
            }
            $buf .= $op['text'];
        }
        $flush();
        return [
            'html' => $html,
            'additions' => $additions,
            'deletions' => $deletions,
            'grain' => $grain,
            'summary' => self::summary($additions, $deletions),
        ];
    }

    private static function units(string $text, string $grain): int
    {
        if ($grain === 'line') {
            $n = preg_match_all('/^[^\n]*\S[^\n]*$/m', $text);
            return $n === false ? 0 : $n;
        }
        $n = preg_match_all('/\S+/u', $text);
        return $n === false ? 0 : $n;
    }

    private static function summary(int $additions, int $deletions): string
    {
        $added = $additions === 1 ? '1 addition' : $additions . ' additions';
        $removed = $deletions === 1 ? '1 deletion' : $deletions . ' deletions';
        return $added . ', ' . $removed;
    }
}
