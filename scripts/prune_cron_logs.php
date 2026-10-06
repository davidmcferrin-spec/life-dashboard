#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * scripts/prune_cron_logs.php — trim storage/logs/*.log to recent lines only.
 *
 * Usage:
 *   php scripts/prune_cron_logs.php
 *   php scripts/prune_cron_logs.php --days=7
 *
 * Dreamhost cron (daily):
 *   /usr/local/bin/php /home/dh_w9tij7/personal-dashboard/scripts/prune_cron_logs.php >> .../storage/logs/prune.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\Config;
use Dashboard\System\CronLogPruner;

$days = Config::int('CRON_LOG_RETENTION_DAYS', 10);

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $override = (int) substr($arg, 7);

        if ($override > 0) {
            $days = $override;
        }
    }
}

$startTime = microtime(true);
$pruner    = new CronLogPruner();

echo '[' . date('Y-m-d H:i:s') . "] Pruning cron logs (retention: {$days} days)..." . PHP_EOL;

$result = $pruner->pruneDirectory(STORAGE_DIR . '/logs', $days);

foreach ($result['files'] as $file) {
    $name = (string) $file['file'];

    if ($file['status'] === 'warn') {
        $message = (string) ($file['message'] ?? 'Skipped');
        echo "[WARN]  {$name}: {$message}" . PHP_EOL;
        continue;
    }

    $linesBefore = (int) ($file['lines_before'] ?? 0);
    $linesAfter  = (int) ($file['lines_after'] ?? 0);
    $bytesBefore = (int) ($file['bytes_before'] ?? 0);
    $bytesAfter  = (int) ($file['bytes_after'] ?? 0);

    if ($file['status'] === 'unchanged') {
        continue;
    }

    $sizeNote = '';

    if ($bytesBefore !== $bytesAfter) {
        $sizeNote = sprintf(
            ' (%s → %s)',
            formatBytes($bytesBefore),
            formatBytes($bytesAfter),
        );
    }

    echo "[INFO]  {$name}: {$linesBefore} → {$linesAfter} lines{$sizeNote}" . PHP_EOL;
}

$elapsed = round(microtime(true) - $startTime, 2);

echo '[' . date('Y-m-d H:i:s') . sprintf(
    '] Done. %d file(s), %d pruned, %d unchanged',
    $result['total'],
    $result['pruned'],
    $result['unchanged'],
) . ($result['warnings'] > 0 ? ", {$result['warnings']} warning(s)" : '') . ". {$elapsed}s" . PHP_EOL;

function formatBytes(int $bytes): string
{
    if ($bytes < 1024) {
        return "{$bytes} B";
    }

    $kb = round($bytes / 1024, 1);

    return "{$kb} KB";
}
