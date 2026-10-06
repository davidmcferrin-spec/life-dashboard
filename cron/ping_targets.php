#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/ping_targets.php — check user-defined ping targets.
 *
 * Run every 15–30 minutes:
 *   /usr/local/bin/php /home/dh_w9tij7/personal-dashboard/cron/ping_targets.php >> .../storage/logs/ping.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Ping\PingService;

$service = PingService::fromDatabase(Database::getInstance());
$count   = $service->runAllActiveChecks();

echo '[' . date('Y-m-d H:i:s') . "] Checked {$count} ping target(s)." . PHP_EOL;
