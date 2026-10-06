<?php

declare(strict_types=1);

namespace Dashboard\System;

/**
 * CronLogPruner — trim storage/logs/*.log to lines from the last N days.
 */
class CronLogPruner
{
    /**
     * @return array{
     *     files: list<array<string, mixed>>,
     *     total: int,
     *     pruned: int,
     *     unchanged: int,
     *     warnings: int
     * }
     */
    public function pruneDirectory(string $dir, int $days): array
    {
        if (!is_dir($dir)) {
            return [
                'files'     => [[
                    'file'    => basename($dir),
                    'status'  => 'warn',
                    'message' => 'Directory missing',
                ]],
                'total'     => 0,
                'pruned'    => 0,
                'unchanged' => 0,
                'warnings'  => 1,
            ];
        }

        $paths = glob(rtrim($dir, '/\\') . '/*.log') ?: [];
        sort($paths);

        $files     = [];
        $pruned    = 0;
        $unchanged = 0;
        $warnings  = 0;

        foreach ($paths as $path) {
            $result = $this->pruneFile($path, $days);
            $files[] = $result;

            if ($result['status'] === 'pruned') {
                $pruned++;
            } elseif ($result['status'] === 'unchanged') {
                $unchanged++;
            } elseif ($result['status'] === 'warn') {
                $warnings++;
            }
        }

        return [
            'files'     => $files,
            'total'     => count($files),
            'pruned'    => $pruned,
            'unchanged' => $unchanged,
            'warnings'  => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pruneFile(string $path, int $days): array
    {
        $basename = basename($path);

        if (!is_file($path)) {
            return [
                'file'    => $basename,
                'status'  => 'warn',
                'message' => 'File missing',
            ];
        }

        if (!is_readable($path)) {
            return [
                'file'    => $basename,
                'status'  => 'warn',
                'message' => 'Not readable',
            ];
        }

        $bytesBefore = (int) filesize($path);

        if ($bytesBefore === 0) {
            return [
                'file'          => $basename,
                'status'        => 'unchanged',
                'lines_before'  => 0,
                'lines_after'   => 0,
                'bytes_before'  => 0,
                'bytes_after'   => 0,
            ];
        }

        $raw = file_get_contents($path);

        if ($raw === false) {
            return [
                'file'    => $basename,
                'status'  => 'warn',
                'message' => 'Unable to read file',
            ];
        }

        $lines        = explode("\n", rtrim($raw, "\r\n"));
        $linesBefore  = count($lines);
        $cutoff       = time() - ($days * 86400);
        $lastTs       = null;
        $kept         = [];

        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches) === 1) {
                $parsed = strtotime($matches[1]);
                $lastTs = $parsed !== false ? $parsed : $lastTs;
            }

            if ($lastTs !== null && $lastTs >= $cutoff) {
                $kept[] = $line;
            }
        }

        $linesAfter = count($kept);

        if ($linesAfter === $linesBefore) {
            return [
                'file'          => $basename,
                'status'        => 'unchanged',
                'lines_before'  => $linesBefore,
                'lines_after'   => $linesAfter,
                'bytes_before'  => $bytesBefore,
                'bytes_after'   => $bytesBefore,
            ];
        }

        if (!is_writable($path)) {
            return [
                'file'    => $basename,
                'status'  => 'warn',
                'message' => 'Not writable',
            ];
        }

        $content     = $kept === [] ? '' : implode("\n", $kept) . "\n";
        $bytesAfter  = strlen($content);
        $tmpPath     = $path . '.tmp';

        if (file_put_contents($tmpPath, $content, LOCK_EX) === false) {
            @unlink($tmpPath);

            return [
                'file'    => $basename,
                'status'  => 'warn',
                'message' => 'Unable to write temp file',
            ];
        }

        if (!rename($tmpPath, $path)) {
            @unlink($tmpPath);

            return [
                'file'    => $basename,
                'status'  => 'warn',
                'message' => 'Unable to replace log file',
            ];
        }

        return [
            'file'          => $basename,
            'status'        => 'pruned',
            'lines_before'  => $linesBefore,
            'lines_after'   => $linesAfter,
            'bytes_before'  => $bytesBefore,
            'bytes_after'   => $bytesAfter,
        ];
    }
}
