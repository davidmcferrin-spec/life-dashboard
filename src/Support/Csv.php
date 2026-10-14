<?php

declare(strict_types=1);

namespace Dashboard\Support;

/**
 * Small RFC 4180 reader. Header cells become lowercase keys.
 * A leading UTF-8 BOM is ignored. Blank lines are skipped.
 */
final class Csv
{
    /**
     * @return list<array<string, string>>
     */
    public static function assocRows(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);
        if (trim($text) === '') {
            return [];
        }

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read CSV.');
        }
        fwrite($handle, $text);
        rewind($handle);

        $header = null;
        $rows   = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null] || (count($cells) === 1 && trim((string) $cells[0]) === '')) {
                continue;
            }
            $cells = array_map(static fn ($c): string => trim((string) $c), $cells);
            if ($header === null) {
                $header = array_map(static fn (string $h): string => strtolower($h), $cells);
                continue;
            }
            $assoc = [];
            foreach ($header as $i => $key) {
                if ($key === '') {
                    continue;
                }
                $assoc[$key] = $cells[$i] ?? '';
            }
            $rows[] = $assoc;
        }
        fclose($handle);
        return $rows;
    }
}
