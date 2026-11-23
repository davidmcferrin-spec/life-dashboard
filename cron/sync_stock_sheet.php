#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/sync_stock_sheet.php — pull the Stocks Google Sheet.
 *
 * Read-only. Skips a user until a service account key is saved.
 * systemd: lifedash-job@stocksheet on weekdays, 8:30–15:30 America/Chicago.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Support\Clock;
use Dashboard\Tracking\Services;

$started = microtime(true);
echo '[' . date('Y-m-d H:i:s') . '] Starting stock sheet sync...' . PHP_EOL;

$services = new Services(Database::getInstance(), Clock::fromConfig());
$result = $services->sheets->syncAll();

echo sprintf(
    '[%s] Done. %d user(s), %d synced, %d skipped, %d failed. %.2fs',
    date('Y-m-d H:i:s'),
    $result['users'],
    $result['synced'],
    $result['skipped'],
    $result['failed'],
    microtime(true) - $started,
) . PHP_EOL;

$attempted = $result['synced'] + $result['failed'];
exit($attempted > 0 && $result['synced'] === 0 ? 1 : 0);
