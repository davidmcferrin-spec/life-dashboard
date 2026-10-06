#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * cron/prune_share_links.php — delete public share links expired beyond grace period.
 *
 * Run daily on Dreamhost:
 *   /usr/local/bin/php /home/dh_w9tij7/personal-dashboard/cron/prune_share_links.php >> .../storage/logs/shares.log 2>&1
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Share\ShareLinkPruner;

$pruner = new ShareLinkPruner(Database::getInstance());
$result = $pruner->purgeExpired();

echo sprintf(
    '[%s] Purged %d expired share link(s) (clipboard: %d, file: %d, cloud: %d).' . PHP_EOL,
    date('Y-m-d H:i:s'),
    $result['total'],
    $result['clipboard'],
    $result['file'],
    $result['cloud'],
);
