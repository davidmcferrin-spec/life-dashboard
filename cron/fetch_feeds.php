#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/fetch_feeds.php — RSS feed fetcher
 *
 * Fetches all active RSS feeds and stores articles in feed_cache.
 * Designed to run via Dreamhost cron every 15-30 minutes.
 *
 * Dreamhost cron command:
 *   /usr/local/bin/php /home/dh_w9tij7/personal-dashboard/cron/fetch_feeds.php >> .../storage/logs/feeds.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\Config;
use Dashboard\DB\Database;
use Dashboard\Feed\RssFeedParser;

$db        = Database::getInstance();
$maxItems  = Config::int('RSS_ITEMS_PER_FEED', 10);
$startTime = microtime(true);

echo '[' . date('Y-m-d H:i:s') . '] Starting feed fetch...' . PHP_EOL;

$sources = $db->fetchAll('SELECT * FROM feed_sources ORDER BY sort_order ASC');

if ($sources === []) {
    echo '[WARN] No feed sources found in database. Run migrate + seed first.' . PHP_EOL;
    exit(0);
}

$totalInserted = 0;
$totalSkipped  = 0;
$totalErrors   = 0;

foreach ($sources as $source) {
    $sourceId = (int) $source['id'];
    $slug     = $source['slug'];
    $url      = $source['url'];

    $xml = RssFeedParser::fetch($url);

    if ($xml === null) {
        echo "[ERROR] Could not fetch {$slug}: {$url}" . PHP_EOL;
        $totalErrors++;
        continue;
    }

    $items = RssFeedParser::parse($xml);

    if ($items === []) {
        echo "[WARN]  No items parsed from {$slug}" . PHP_EOL;
        continue;
    }

    $inserted = RssFeedParser::cacheItemsForSource($db, $sourceId, $items, $maxItems);
    $attempted = count(array_slice($items, 0, $maxItems));
    $skipped   = max(0, $attempted - $inserted);

    echo "[OK]    {$slug}: +{$inserted} new, {$skipped} skipped" . PHP_EOL;
    $totalInserted += $inserted;
    $totalSkipped  += $skipped;
}

RssFeedParser::pruneAllSources($db, $maxItems * 3);

$maxAgeDays = Config::int('RSS_CACHE_MAX_AGE_DAYS', 14);
$pruned     = RssFeedParser::pruneOlderThan($db, $maxAgeDays);
if ($pruned > 0) {
    echo "[OK]    Pruned {$pruned} article(s) older than {$maxAgeDays} days" . PHP_EOL;
}

$elapsed = round(microtime(true) - $startTime, 2);
echo '[' . date('Y-m-d H:i:s') . "] Done. +{$totalInserted} new, {$totalSkipped} skipped, {$totalErrors} errors. {$elapsed}s" . PHP_EOL;
