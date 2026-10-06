#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/fetch_packages.php — poll carrier APIs for due package watches.
 *
 * Run every 2 hours on Dreamhost:
 *   /usr/local/bin/php /home/dh_w9tij7/personal-dashboard/cron/fetch_packages.php >> .../storage/logs/packages.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Package\PackageWatchService;

$service = PackageWatchService::fromDatabase(Database::getInstance());
$polled  = $service->pollDueWatches();

echo '[' . date('Y-m-d H:i:s') . "] Polled {$polled} package watch(es)." . PHP_EOL;
