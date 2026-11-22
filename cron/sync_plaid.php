#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/sync_plaid.php — refresh linked bank balances and new transactions.
 *
 *   php cron/sync_plaid.php
 * systemd: lifedash-job@plaid every 3 hours.
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
echo '[' . date('Y-m-d H:i:s') . '] Starting Plaid sync...' . PHP_EOL;

$services = new Services(Database::getInstance(), Clock::fromConfig());
$result = $services->plaid->syncAll();

echo sprintf(
    '[%s] Done. %d user(s), %d item(s), %d failed. %.2fs',
    date('Y-m-d H:i:s'),
    $result['users'],
    $result['items'],
    $result['failed'],
    microtime(true) - $started,
) . PHP_EOL;

exit($result['failed'] > 0 && $result['items'] === $result['failed'] ? 1 : 0);
