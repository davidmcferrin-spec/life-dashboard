#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/sample_host.php — read this machine and keep 90 days of samples.
 *
 *   php cron/sample_host.php
 *   php cron/sample_host.php --json
 * systemd: lifedash-job@host every 5 minutes.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Host\HostMonitor;

$monitor = new HostMonitor(Database::getInstance());

if (in_array('--json', $argv ?? [], true)) {
    echo json_encode($monitor->capture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit(0);
}

$started = microtime(true);
echo '[' . date('Y-m-d H:i:s') . '] Sampling host...' . PHP_EOL;
$result = $monitor->sample();
echo sprintf(
    '[%s] Done. %s, %d point(s), tunnel %s. %.2fs',
    date('Y-m-d H:i:s'),
    $result['skipped'] ? 'skipped' : 'stored',
    $result['points'],
    $result['tunnel'],
    microtime(true) - $started,
) . PHP_EOL;
