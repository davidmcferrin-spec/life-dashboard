#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/fetch_stocks.php — refresh Alpha Vantage quotes (rate-limited).
 *
 * Alpha Vantage free tier: 25 requests/day — run 2–4× daily max.
 * 13-second pause between symbols (5 calls/minute limit).
 *
 *   /usr/local/bin/php /home/dh_w9tij7/personal-dashboard/cron/fetch_stocks.php >> .../storage/logs/stocks.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Stock\StockService;

if (Config::maybe('ALPHAVANTAGE_API_KEY') === null) {
    echo '[' . date('Y-m-d H:i:s') . '] ALPHAVANTAGE_API_KEY not set — skipping.' . PHP_EOL;
    exit(0);
}

$startTime = microtime(true);
$service   = StockService::fromDatabase(Database::getInstance());

echo '[' . date('Y-m-d H:i:s') . '] Starting stock refresh...' . PHP_EOL;

$result  = $service->refreshAllActive();
$entries = $result['entries'];

echo '[INFO]  ' . count($entries) . ' active symbol row(s) processed' . PHP_EOL;

foreach ($entries as $entry) {
    $label = match ($entry['status']) {
        'ok'          => 'OK',
        'cached'      => 'CACHE',
        'rate_limit'  => 'WARN',
        default       => 'ERROR',
    };

    $suffix = $entry['status'] === 'cached'
        ? $entry['detail'] . ' (file cache, skipped API)'
        : $entry['detail'];

    if ($entry['status'] === 'rate_limit') {
        $suffix = 'rate limited — skipping remaining symbols';
    }

    echo sprintf(
        '[%-5s] %s (user #%d): %s',
        $label,
        $entry['symbol'],
        $entry['user_id'],
        $suffix,
    ) . PHP_EOL;
}

$elapsed = round(microtime(true) - $startTime, 2);
$rateNote = $result['rate_limited'] ? ', rate-limited' : '';

echo '[' . date('Y-m-d H:i:s') . sprintf(
    '] Done. %d updated, %d cached, %d failed%s. %ss',
    $result['updated'],
    $result['cached'],
    $result['failed'],
    $rateNote,
    $elapsed,
) . PHP_EOL;
