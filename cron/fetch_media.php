#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Refresh YouTube, Reddit, and X cards for every widget that lists sources.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require dirname(__DIR__) . '/bootstrap.php';

use Dashboard\DB\Database;
use Dashboard\Media\MediaService;

$db = Database::getInstance();
$start = microtime(true);
echo '[' . gmdate('Y-m-d H:i:s') . '] Starting media fetch...' . PHP_EOL;

$users = $db->fetchAll(
    "SELECT DISTINCT user_id FROM dashboard_widgets WHERE type IN ('youtube', 'reddit', 'x')"
);
$media = new MediaService($db);
foreach ($users as $row) {
    $userId = (int) $row['user_id'];
    try {
        $media->sync($userId, true, 100);
        echo "[OK]    user {$userId}" . PHP_EOL;
    } catch (\Throwable $e) {
        echo "[ERROR] user {$userId}: " . $e->getMessage() . PHP_EOL;
    }
}

$elapsed = round(microtime(true) - $start, 2);
echo '[' . gmdate('Y-m-d H:i:s') . '] Done. ' . count($users) . " user(s). {$elapsed}s" . PHP_EOL;
