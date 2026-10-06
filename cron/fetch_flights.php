#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/fetch_flights.php — poll OpenSky (or FlightAware) for due flight watches.
 *
 * Run every 30 minutes on Dreamhost:
 *   /usr/local/bin/php /home/dh_w9tij7/personal-dashboard/cron/fetch_flights.php >> .../storage/logs/flights.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\Flight\FlightWatchService;
use Dashboard\DB\Database;

$service = FlightWatchService::fromDatabase(Database::getInstance());
$polled  = $service->pollDueWatches();

echo '[' . date('Y-m-d H:i:s') . "] Polled {$polled} flight watch(es)." . PHP_EOL;
